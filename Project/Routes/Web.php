<?php

/** Application routes use the current path-first API. */
use App\Plugins\View;
use App\Plugins\Route;

Route::path('/')
    ->get(static fn () => View::render('home.welcome', [
        'title' => 'Welcome to SqueHub',
    ]))
    ->named('welcome.page');
