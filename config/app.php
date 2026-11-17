<?php
return ['name' => getenv('APP_NAME') ?: 'Spare', 'session_idle_timeout' => max(60, (int)(getenv('SESSION_IDLE_TIMEOUT') ?: 1800))];
