<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Cmd;

use Blnk\Config\Configuration;
use Blnk\Core\Blnk;

/**
 * blnkInstance holds the Blnk instance and its configuration.
 * This is used to store the runtime instance and configuration globally within the application.
 *
 * (Go: `blnkInstance` struct, cmd/main.go.) The worker task handlers that Go
 * declares as methods on `*blnkInstance` (cmd/workers.go) live on
 * {@see WorkersCommand}, which wraps this holder.
 */
final class BlnkInstance
{
    /** Blnk object initialized from configuration (Go: `blnk *blnk.Blnk`). */
    public ?Blnk $blnk = null;

    /** Configuration object holding runtime settings (Go: `cnf *config.Configuration`). */
    public ?Configuration $cnf = null;

    /**
     * PHP-only: absolute path of the configuration file {@see Application::loadInstance()}
     * loaded, handed to the `php -S` child of `blnk start` through BLNK_CONFIG
     * so the front controller (public/index.php) reads the same file.
     */
    public string $configFile = '';

    public function __construct(?Blnk $blnk = null, ?Configuration $cnf = null)
    {
        $this->blnk = $blnk;
        $this->cnf = $cnf;
    }
}
