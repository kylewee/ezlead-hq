<?php
opcache_reset();
echo json_encode(['opcache' => 'cleared', 'time' => time()]);
