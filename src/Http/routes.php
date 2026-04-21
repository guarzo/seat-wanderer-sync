<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace'  => 'Guarzo\Seat\WandererSync\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix'     => 'wanderer-sync',
], function () {
    Route::get('/settings', [
        'as'         => 'wanderer-sync::settings',
        'uses'       => 'SettingsController@list',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/mapping', [
        'as'         => 'wanderer-sync::createMapping',
        'uses'       => 'SettingsController@createMapping',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/mapping/delete', [
        'as'         => 'wanderer-sync::deleteMapping',
        'uses'       => 'SettingsController@deleteMapping',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/accesslist', [
        'as'         => 'wanderer-sync::createWandererAccessList',
        'uses'       => 'SettingsController@createWandererAccessList',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/accesslist/delete', [
        'as'         => 'wanderer-sync::deleteInstance',
        'uses'       => 'SettingsController@deleteInstance',
        'middleware' => 'can:wanderer-sync.edit',
    ]);
});
