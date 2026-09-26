<?php

namespace App\Enums;

/**
 * The four legal pages of the service. The slug is the public URL; the type never changes once documents exist.
 */
enum LegalDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Cookies = 'cookies';
    case Withdrawal = 'withdrawal';

    public function slug(): string
    {
        return match ($this) {
            self::Terms => 'vop',
            self::Privacy => 'ochrana-osobnych-udajov',
            self::Cookies => 'cookies',
            self::Withdrawal => 'odstupenie-od-zmluvy',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Všeobecné obchodné podmienky',
            self::Privacy => 'Informácie o spracúvaní osobných údajov',
            self::Cookies => 'Cookies a voliteľné služby',
            self::Withdrawal => 'Odstúpenie od zmluvy a reklamácie',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Terms => 'VOP',
            self::Privacy => 'Ochrana osobných údajov',
            self::Cookies => 'Cookies',
            self::Withdrawal => 'Odstúpenie od zmluvy',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    /** Documents a paid checkout needs published before payments may go live (acceptance test 20). */
    public function requiredForCheckout(): bool
    {
        return $this !== self::Cookies;
    }
}
