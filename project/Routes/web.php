<?php 
use App\Core\View;

$router->add('GET', '/', function() {
    return View::render('home.welcome');
}, 'welcome.page');

