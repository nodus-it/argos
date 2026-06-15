<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Marker base for inbound DTOs: typed, immutable anti-corruption wrappers around
 * an external API/transport response. Provider-specific JSON is normalized once,
 * at the port, into a typed shape — instead of `??`-juggling raw array keys
 * across the domain.
 *
 * Convention: inbound DTOs live in a `…\DTO` namespace, extend this base, and
 * expose a `from<Source>()` named constructor that takes the raw payload. The
 * base is `readonly`, so every subclass is immutable by construction. The arch
 * rule "classes in a `…\DTO` namespace extend Dto" anchors the convention.
 */
abstract readonly class Dto {}
