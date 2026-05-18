<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Registry\DeviceCapabilities;

DeviceCapabilities::setDatabasePdo(null);
DeviceCapabilities::setProfilesPath(__DIR__ . '/../config/capabilities.json');
DeviceCapabilities::setCacheTtl(3600);
