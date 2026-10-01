<?php

namespace App\Enums;

enum RecordSource: string
{
    case RegistrarImport = 'registrar_import';
    case SelfRegistered = 'self_registered';
    case Erp = 'erp_sync';

    public function label(): string
    {
        return match ($this) {
            self::RegistrarImport => 'Registrar import',
            self::SelfRegistered => 'Self-registered',
            self::Erp => 'SorotiUniERP',
        };
    }
}
