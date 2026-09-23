<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Controller;

use OCA\FtsSql\AppInfo\Application;
use OCA\FtsSql\Service\ConfigService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The two OCS settings endpoints, administrator-only by the framework's
 * default posture (no #[NoAdminRequired] on either) — the administration
 * scope of openapi.json. The language is validated against what the
 * running engine itself accepts, because a name the engine lacks would
 * make every PostgreSQL write fail; a non-positive content budget is
 * refused the same way.
 */
final class SettingsController extends OCSController {
	public function __construct(
		IRequest $request,
		private ConfigService $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Store the text search language every future index write will stem with
	 *
	 * @param string $language a text search configuration name, one of those the running engine itself reports
	 * @return DataResponse<Http::STATUS_OK, array{language: string}, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: Language stored
	 * 422: The running engine does not report this language
	 */
	#[ApiRoute(verb: 'PUT', url: '/settings/language')]
	public function putLanguage(string $language): DataResponse {
		if (!in_array($language, $this->config->availableLanguages(), true)) {
			return new DataResponse(
				['message' => 'unknown text search language'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}
		$this->config->setLanguage($language);
		return new DataResponse(['language' => $language]);
	}

	/**
	 * Store how many bytes of extracted text are indexed per document
	 *
	 * @param int $contentBytes the per-document content budget, in bytes
	 * @return DataResponse<Http::STATUS_OK, array{contentBytes: int}, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: Budget stored
	 * 422: The budget is not a positive number of bytes
	 */
	#[ApiRoute(verb: 'PUT', url: '/settings/content-bytes')]
	public function putContentBytes(int $contentBytes): DataResponse {
		if ($contentBytes <= 0) {
			return new DataResponse(
				['message' => 'the content budget must be a positive number of bytes'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}
		$this->config->setContentBytes($contentBytes);
		return new DataResponse(['contentBytes' => $contentBytes]);
	}
}
