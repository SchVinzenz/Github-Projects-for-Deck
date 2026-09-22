<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'settings#getAdmin', 'url' => '/api/v1/admin', 'verb' => 'GET'],
		['name' => 'settings#setAdmin', 'url' => '/api/v1/admin', 'verb' => 'PUT'],
		['name' => 'settings#listMappings', 'url' => '/api/v1/mappings', 'verb' => 'GET'],
		['name' => 'settings#createMapping', 'url' => '/api/v1/mappings', 'verb' => 'POST'],
		['name' => 'settings#updateMapping', 'url' => '/api/v1/mappings/{id}', 'verb' => 'PUT'],
		['name' => 'settings#deleteMapping', 'url' => '/api/v1/mappings/{id}', 'verb' => 'DELETE'],
		['name' => 'settings#setUserMap', 'url' => '/api/v1/mappings/{id}/users', 'verb' => 'PUT'],
		['name' => 'settings#listBoards', 'url' => '/api/v1/deck/boards', 'verb' => 'GET'],
		['name' => 'settings#listProjects', 'url' => '/api/v1/github/projects', 'verb' => 'GET'],
		['name' => 'settings#listRepositories', 'url' => '/api/v1/github/repositories', 'verb' => 'GET'],
		['name' => 'settings#githubStatus', 'url' => '/api/v1/github/status', 'verb' => 'GET'],
		['name' => 'settings#setUserToken', 'url' => '/api/v1/github/token', 'verb' => 'PUT'],
		['name' => 'settings#disconnectGithub', 'url' => '/api/v1/github/token', 'verb' => 'DELETE'],
		['name' => 'oAuth#start', 'url' => '/oauth/start', 'verb' => 'GET'],
		['name' => 'oAuth#callback', 'url' => '/oauth/callback', 'verb' => 'GET'],
		['name' => 'sync#trigger', 'url' => '/api/v1/sync/{id}', 'verb' => 'POST'],
		['name' => 'sync#status', 'url' => '/api/v1/sync/{id}/status', 'verb' => 'GET'],
		['name' => 'webhook#github', 'url' => '/webhook/github', 'verb' => 'POST'],
	],
];
