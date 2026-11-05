<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/** D-1169: how loudly a place type says that something does not fit into it; «Fehler» refuses, «Warnung» where none is set. */
enum FitLevel: string
{
    case Info    = 'info';
    case Warning = 'warning';
    case Error   = 'error';
}
