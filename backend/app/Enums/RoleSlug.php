<?php

namespace App\Enums;

enum RoleSlug: string
{
    case Alumni = 'alumni';
    case Registrar = 'registrar';
    case IctAdmin = 'ict_admin';
    case QaViewer = 'qa_viewer';

    public function label(): string
    {
        return match ($this) {
            self::Alumni => 'Alumni',
            self::Registrar => 'Registrar staff',
            self::IctAdmin => 'ICT admin',
            self::QaViewer => 'QA / Dean viewer',
        };
    }

    /**
     * Roles that may sign in to the staff admin dashboard.
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Registrar, self::IctAdmin, self::QaViewer];
    }
}
