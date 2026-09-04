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

namespace Blnk\Core;

/**
 * LineageWorker is the port of the root-package file `lineage_worker.go`.
 *
 * The Go file declares no `*Blnk` methods: it holds the `LineageOutboxProcessor`
 * type and its constructor `NewLineageOutboxProcessor`, which became the
 * standalone class {@see LineageOutboxProcessor} (with
 * `LineageOutboxProcessor::newLineageOutboxProcessor()`). Per the Core contract
 * every Go file maps to one trait composed into {@see Blnk}, so this trait
 * exists as the file's anchor and is intentionally empty.
 *
 * The workers CLI drives the processor through
 * `LineageOutboxProcessor::run($stopFlag)` (the Go background loop) or
 * `LineageOutboxProcessor::runOnce()` (a single poll).
 */
trait LineageWorker
{
}
