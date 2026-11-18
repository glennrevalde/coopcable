<?php
declare(strict_types=1);

defined('APP_PORTAL') || exit;   // opened only through portal/index.php

// log-out is a POST (with the form's token), so a link on another site cannot log the subscriber out
if (is_post()) {
    IspPortal::logout();
    redirect(IspPortal::url('login'), 'You are logged out.', 'info');
}
redirect(IspPortal::url(IspPortal::customer() ? 'home' : 'login'));
