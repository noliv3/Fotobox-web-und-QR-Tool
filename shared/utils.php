<?php
// Utility-Funktionen (Stub)

function sanitizeInput(mixed $value): mixed
{
    // TODO: Eingaben nach Typ, Länge und erlaubten Zeichen validieren.
    return $value;
}

function generateToken(): string
{
    // TODO: Sichere Token-Erzeugung für Sessions/Links implementieren.
    return 'TODO_TOKEN';
}

function logEvent(string $event, array $context = []): void
{
    // TODO: Strukturierte Logs ohne sensible Daten schreiben.
}

function nowUtc(): string
{
    // TODO: Einheitliches UTC-Zeitformat für Logs/Events festlegen.
    return '1970-01-01T00:00:00Z';
}

function ensureDir(string $path): bool
{
    // TODO: Sichere Verzeichnisprüfung und Erstellung implementieren.
    return false;
}
