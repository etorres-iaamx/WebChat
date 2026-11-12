<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\WebSocket\RelayServer;

(new RelayServer(webchat_config()))->run();
