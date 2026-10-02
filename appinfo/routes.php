<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'site#index', 'url' => '/sites', 'verb' => 'GET'],
		['name' => 'site#create', 'url' => '/sites', 'verb' => 'POST'],
		['name' => 'site#destroy', 'url' => '/sites/{id}', 'verb' => 'DELETE'],
		['name' => 'site#share', 'url' => '/sites/{id}/share', 'verb' => 'POST'],
		['name' => 'site#shares', 'url' => '/sites/{id}/shares', 'verb' => 'GET'],
		['name' => 'sharee#index', 'url' => '/sharees', 'verb' => 'GET'],
		['name' => 'preferences#index', 'url' => '/prefs', 'verb' => 'GET'],
		['name' => 'preferences#update', 'url' => '/prefs', 'verb' => 'PUT'],
		['name' => 'search#search', 'url' => '/search', 'verb' => 'GET'],
		['name' => 'page#tree', 'url' => '/s/{siteId}/tree', 'verb' => 'GET'],
		['name' => 'page#page', 'url' => '/s/{siteId}/page/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+']],
		['name' => 'asset#file', 'url' => '/s/{siteId}/file/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.+']],
	],
];
