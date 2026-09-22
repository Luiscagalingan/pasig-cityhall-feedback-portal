<?php
declare(strict_types=1);

function client_count_year(mixed $value): int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[0-9]{4}$/', (string)$value)
        || (int)$value < 1900 || (int)$value > 9998) {
        throw new DomainException('Select a valid calendar year (1900 to 9998).');
    }
    return (int)$value;
}

function client_output_month(mixed $selection = null, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('now');
    $selection = $selection ?? $today->format('Y-m');
    if (!is_string($selection) || !preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/', $selection, $parts)) {
        throw new DomainException('Select a valid month and year.');
    }
    client_count_year($parts[1]);
    $start = new DateTimeImmutable($selection . '-01');
    return ['month' => $selection, 'start' => $start->format('Y-m-d'),
        'end' => $start->format('Y-m-t'), 'label' => $start->format('F Y')];
}
