<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Néfix Estrada
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FtsSql\Controller;

use OCA\FtsSql\AppInfo\Application;
use OCA\FtsSql\ConfigLexicon;
use OCA\FtsSql\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The two OCS-style settings endpoints, administrator-only by the framework's
 * default posture (no #[NoAdminRequired] on either). The closed language list
 * is enforced here because an arbitrary regconfig name would make every
 * PostgreSQL write fail; a non-positive content budget is refused the same
 * way.
 */
final class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private ConfigService $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/language')]
	public function putLanguage(string $language): DataResponse {
		if (!in_array($language, ConfigLexicon::availableLanguages(), true)) {
			return new DataResponse(
				['message' => 'unknown text search language'],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}
		$this->config->setLanguage($language);
		return new DataResponse(['language' => $language]);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/content-bytes')]
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
