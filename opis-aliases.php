<?php
/* ===========================================================================
 * Copyright 2018 Zindex Software
 * Copyright 2026 noir-framework
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

declare(strict_types=1);

/*
 * Backward compatibility for code written against opis/database.
 *
 * Any Opis\Database\* class is resolved lazily to its Noirapi\Database\*
 * counterpart the first time it is requested, and a silenced
 * E_USER_DEPRECATED notice is raised. The aliases will be removed in 6.0.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Opis\\Database\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $target = 'Noirapi\\Database\\' . substr($class, strlen($prefix));
    if (!class_exists($target) && !interface_exists($target) && !trait_exists($target)) {
        return;
    }

    @trigger_error(
        sprintf('Class "%s" is deprecated since noirapi/database 5.0, use "%s" instead.', $class, $target),
        E_USER_DEPRECATED,
    );
    class_alias($target, $class);
});
