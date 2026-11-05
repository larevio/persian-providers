<?php

namespace Larevio\PersianProviders\Enums;

enum Bank
{
    case MELLAT;
    case MELLI;
    case SADERAT;
    case SEPAH;
    case TEJARAT;
    case KESHAVARZI;
    case MASKAN;
    case POST_BANK;
    case TOSEE_SADERAT;
    case TOSEE_TAVON;
    case PASARGAD;
    case PARSIAN;
    case SANAT_VA_MADAN;
    case EGHTESAD_NOVIN;
    case IRAN_ZAMIN;
    case KHAVAR_MIANEH;
    case DAY;
    case SAMAN;
    case SARMAYE;
    case SINA;
    case SHAHR;
    case RESALAT;
    case KAR_AFARIN;
    case GARDESHGARI;
    case AYANDEH;
    case MEHR;
    case REFAH_KARGARAN;
    case MELAL;

    public function fa(): string
    {
        return match ($this) {
            Bank::MELLAT => 'ملت',
            Bank::MELLI => 'ملی',
            Bank::SADERAT => 'صادرات',
            Bank::SEPAH => 'سپه',
            Bank::TEJARAT => 'تجارت',
            Bank::KESHAVARZI => 'کشاورزی',
            Bank::MASKAN => 'مسکن',
            Bank::POST_BANK => 'پست بانک',
            Bank::TOSEE_SADERAT => 'توسعه صادرات',
            Bank::TOSEE_TAVON => 'توسعه تعاون',
            Bank::PASARGAD => 'پاسارگاد',
            Bank::PARSIAN => 'پارسیان',
            Bank::SANAT_VA_MADAN => 'صنعت و معدن',
            Bank::EGHTESAD_NOVIN => 'اقتصاد نوین',
            Bank::IRAN_ZAMIN => 'ایران زمین',
            Bank::KHAVAR_MIANEH => 'خاورمیانه',
            Bank::DAY => 'دی',
            Bank::SAMAN => 'سامان',
            Bank::SARMAYE => 'سرمایه',
            Bank::SINA => 'سینا',
            Bank::SHAHR => 'شهر',
            Bank::RESALAT => 'رسالت',
            Bank::KAR_AFARIN => 'کار آفرین',
            Bank::GARDESHGARI => 'گردشگری',
            Bank::AYANDEH => 'آینده',
            Bank::MEHR => 'مهر ایران',
            Bank::REFAH_KARGARAN => 'رفاه کارگران',
            Bank::MELAL => 'ملل',
        };
    }

    public function color(): string
    {
        return match ($this) {
            Bank::MELLAT => '#dc2626',
            Bank::MELLI => '#ca8a04',
            Bank::SADERAT => '#3b82f6',
            Bank::SEPAH => '#f97316',
            Bank::TEJARAT => '#0284c7',
            Bank::KESHAVARZI => '#22c55e',
            Bank::MASKAN, Bank::KHAVAR_MIANEH => '#ea580c',
            Bank::POST_BANK, Bank::KAR_AFARIN => '#059669',
            Bank::TOSEE_SADERAT, Bank::MEHR => '#15803d',
            Bank::TOSEE_TAVON => '#0d9488',
            Bank::PASARGAD => '#f59e0b',
            Bank::PARSIAN, Bank::SHAHR => '#b91c1c',
            Bank::SANAT_VA_MADAN => '#a16207',
            Bank::EGHTESAD_NOVIN => '#7c3aed',
            Bank::IRAN_ZAMIN => '#c026d3',
            Bank::DAY => '#06b6d4',
            Bank::SAMAN => '#2563eb',
            Bank::SARMAYE, Bank::SINA => '#1e40af',
            Bank::RESALAT => '#0891b2',
            Bank::GARDESHGARI => '#991b1b',
            Bank::AYANDEH => '#b45309',
            Bank::REFAH_KARGARAN => '#4f46e5',
            Bank::MELAL => '#3730a3',
        };
    }

    public static function fromIban(string $iban): ?Bank
    {
        $iban = preg_replace('/\s+/', '', strtoupper($iban));
        if (! preg_match('/^(?:IR)?[0-9]{24}$/', $iban)) {
            return null;
        }
        $bankCode = substr(str_starts_with($iban, 'IR') ? substr($iban, 2) : $iban, 2, 3);

        return Bank::fromBankCode($bankCode);
    }

    public static function fromBankCode(string|int $code): ?Bank
    {
        $code = str_pad((string) $code, 3, '0', STR_PAD_LEFT);

        return match ($code) {
            '012' => Bank::MELLAT,
            '017' => Bank::MELLI,
            '019' => Bank::SADERAT,
            '015' => Bank::SEPAH,
            '018' => Bank::TEJARAT,
            '016' => Bank::KESHAVARZI,
            '014' => Bank::MASKAN,
            '021' => Bank::POST_BANK,
            '020' => Bank::TOSEE_SADERAT,
            '057' => Bank::PASARGAD,
            '054' => Bank::PARSIAN,
            '011' => Bank::SANAT_VA_MADAN,
            '055' => Bank::EGHTESAD_NOVIN,
            '069' => Bank::IRAN_ZAMIN,
            '078' => Bank::KHAVAR_MIANEH,
            '066' => Bank::DAY,
            '056' => Bank::SAMAN,
            '058' => Bank::SARMAYE,
            '059' => Bank::SINA,
            '061' => Bank::SHAHR,
            '070' => Bank::RESALAT,
            '053' => Bank::KAR_AFARIN,
            '064' => Bank::GARDESHGARI,
            '062' => Bank::AYANDEH,
            '060' => Bank::MEHR,
            '013' => Bank::REFAH_KARGARAN,
            '075' => Bank::MELAL,
            default => null,
        };
    }

    public static function fromCard(string $card): ?Bank
    {
        $card = preg_replace('/[\s-]+/', '', $card);
        if (! preg_match('/^[0-9]{6}[0-9*xX]{10}$/', $card)) {
            return null;
        }
        $bin = substr($card, 0, 6);

        return match ($bin) {
            '627961' => Bank::SANAT_VA_MADAN,
            '610433' => Bank::MELLAT,
            '589463' => Bank::REFAH_KARGARAN,
            '628023' => Bank::MASKAN,
            '589210', '636949', '505801', '627381', '639370', '639599' => Bank::SEPAH,
            '603770' => Bank::KESHAVARZI,
            '603799' => Bank::MELLI,
            '627353', '585983' => Bank::TEJARAT,
            '603769' => Bank::SADERAT,
            '627648' => Bank::TOSEE_SADERAT,
            '627760' => Bank::POST_BANK,
            '502908' => Bank::TOSEE_TAVON,
            '627488' => Bank::KAR_AFARIN,
            '622106' => Bank::PARSIAN,
            '627412' => Bank::EGHTESAD_NOVIN,
            '621986' => Bank::SAMAN,
            '502229' => Bank::PASARGAD,
            '639607' => Bank::SARMAYE,
            '639346' => Bank::SINA,
            '606373' => Bank::MEHR,
            '504706' => Bank::SHAHR,
            '636214' => Bank::AYANDEH,
            '505416' => Bank::GARDESHGARI,
            '585947', '505809' => Bank::KHAVAR_MIANEH,
            '502938' => Bank::DAY,
            '505785' => Bank::IRAN_ZAMIN,
            '504172' => Bank::RESALAT,
            default => null,
        };
    }
}
