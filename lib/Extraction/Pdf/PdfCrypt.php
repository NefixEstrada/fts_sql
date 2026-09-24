<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Extraction\Pdf;

/**
 * The standard security handler, for the one class of encrypted PDF
 * every reader opens without asking anything: a document whose user
 * password is empty — permission-restricted, never locked, and the
 * shape of the 21 MiB reference file the Milestone 3 route decision
 * measured. A document that actually carries a user password, or a
 * handler this class does not implement (AES-256 revisions 5 and 6,
 * anything but /Standard), decrypts nothing and stays the Encrypted
 * cause: title, access and tags.
 *
 * Algorithm 3.2 derives the file key from the (empty) password, /O,
 * /P and the file ID, with revision 3's fifty extra rounds; the user
 * password is validated against /U before anything is decrypted, so
 * a passworded document is refused at the door rather than decrypted
 * to garbage. Algorithm 3.1 then derives each stream's or string's
 * key from its own object number, and RC4 — or AES-128 for the AESV2
 * crypt filter — runs under it.
 */
final class PdfCrypt {
	private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
		. "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

	private function __construct(
		private readonly string $key,
		private readonly bool $aes,
	) {
	}

	/**
	 * Build the handler for an /Encrypt dict, or null when the document
	 * is not decryptable here: a real user password, AES-256 revisions,
	 * or a filter that is not the standard one.
	 *
	 * @param array<string, mixed> $encrypt the /Encrypt dict, resolved
	 * @param array<string, mixed> $trailer the trailer carrying /ID
	 */
	public static function build(array $encrypt, PdfDocument $document, array $trailer): ?self {
		$filter = $encrypt['Filter'] ?? null;
		if (!$filter instanceof PdfName || $filter->name !== 'Standard') {
			return null;
		}

		$v = (int)($encrypt['V'] ?? 0);
		$r = (int)($encrypt['R'] ?? 0);
		if (!in_array([$v, $r], [[1, 2], [1, 3], [2, 3], [4, 4]], true)) {
			return null;
		}

		$keyLength = $v === 1 ? 5 : max(5, min((int)($encrypt['Length'] ?? 40) >> 3, 16));
		$aes = false;
		if ($v === 4) {
			$stm = $encrypt['StmF'] ?? null;
			$str = $encrypt['StrF'] ?? null;
			$filters = [];
			foreach ([$stm ?? null, $str ?? null] as $named) {
				if ($named instanceof PdfName) {
					$filters[] = $named->name;
				}
			}
			$filters = $filters === [] ? ['StdCF'] : $filters;
			if (count(array_unique($filters)) !== 1) {
				return null;
			}
			$cfName = $filters[0];
			$cf = $document->resolve($encrypt['CF'] ?? null);
			$method = is_array($cf) && isset($cf[$cfName]) ? $document->resolve($cf[$cfName]) : null;
			$cfm = is_array($method) ? ($method['CFM'] ?? null) : null;
			if (!($cfm instanceof PdfName)) {
				return null;
			}
			if ($cfm->name === 'AESV2') {
				$aes = true;
				$keyLength = 16;
			} elseif ($cfm->name !== 'V2') {
				return null; // Identity means no encryption; AESV5 is not ours
			}
		}

		$o = $encrypt['O'] ?? null;
		$u = $encrypt['U'] ?? null;
		$p = $encrypt['P'] ?? null;
		if (!is_string($o) || !is_string($u) || !is_int($p) || strlen($o) < 32 || strlen($u) < 32) {
			return null;
		}

		$id = '';
		$idValue = $trailer['ID'] ?? null;
		if (is_array($idValue) && isset($idValue[0]) && is_string($idValue[0])) {
			$id = $idValue[0];
		}

		$encryptMetadata = $encrypt['EncryptMetadata'] ?? true;
		$encryptMetadata = is_bool($encryptMetadata) ? $encryptMetadata : true;
		$trailerBytes = self::padding('');
		$hash = md5($trailerBytes . $o . pack('V', $p & 0xFFFFFFFF) . $id
			. ($r >= 4 && !$encryptMetadata ? "\xFF\xFF\xFF\xFF" : ''), true);
		if ($r >= 3) {
			for ($i = 0; $i < 50; $i++) {
				$hash = md5(substr($hash, 0, $keyLength), true);
			}
		}
		$key = substr($hash, 0, $keyLength);

		if (!self::userPasswordMatches($u, $key, $id, $r)) {
			return null; // a document that asks for a password is not ours to open
		}

		return new self($key, $aes);
	}

	private static function padding(string $password): string {
		return substr($password . self::PADDING, 0, 32);
	}

	private static function userPasswordMatches(string $u, string $key, string $id, int $r): bool {
		if ($r >= 3) {
			$hash = md5(self::padding('') . $id, true);
			$cipher = self::rc4($key, $hash);
			for ($i = 1; $i <= 19; $i++) {
				$cipher = self::rc4($key ^ str_repeat(chr($i), strlen($key)), $cipher);
			}
			return substr($cipher, 0, 16) === substr($u, 0, 16);
		}
		return self::rc4($key, self::padding('')) === substr($u, 0, 32);
	}

	/**
	 * One stream's or string's plaintext, decrypted under its own
	 * object's key.
	 */
	public function decrypt(string $data, int $object, int $generation): string {
		// algorithm 3.1: the file key, the object number's low three
		// bytes and the generation's low two, hashed together
		$key = md5($this->key . substr(pack('V', $object), 0, 3) . pack('v', $generation), true);
		$key = substr($key, 0, min(strlen($this->key) + 5, 16));

		if (!$this->aes) {
			return self::rc4($key, $data);
		}

		if (strlen($data) < 32) {
			return '';
		}
		$plain = openssl_decrypt(substr($data, 16), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
		return $plain === false ? '' : $plain;
	}

	private static function rc4(string $key, string $data): string {
		static $bytes = null;
		$bytes ??= array_map(chr(...), range(0, 255));

		$s = range(0, 255);
		$j = 0;
		$keyLength = strlen($key);
		for ($i = 0; $i < 256; $i++) {
			$j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 0xFF;
			[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
		}

		// the keystream is built byte by byte, then one string XOR
		// applies it — measurably faster than concatenating per byte
		$keystream = '';
		$i = $j = 0;
		$len = strlen($data);
		for ($at = 0; $at < $len; $at++) {
			$i = ($i + 1) & 0xFF;
			$j = ($j + $s[$i]) & 0xFF;
			[$s[$i], $s[$j]] = [$s[$j], $s[$i]];
			$keystream .= $bytes[$s[($s[$i] + $s[$j]) & 0xFF]];
		}
		return $data ^ $keystream;
	}
}
