<?php

/** @generate-class-entries */

function phpser_serialize(mixed $value): string {}

function phpser_unserialize(string $str, array $options = []): mixed {}

function phpser_serialize_signed(mixed $value, #[\SensitiveParameter] string $key): string {}

function phpser_unserialize_signed(string $payload, #[\SensitiveParameter] string $key, array $options = []): mixed {}

function phpser_serialize_keyed(mixed $value, string $context): string {}

function phpser_unserialize_keyed(string $payload, string $context, array $options = []): mixed {}
