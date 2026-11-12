<?php

namespace App\Support;

use Illuminate\Mail\Message;

class BrandedEmail
{
    public static function apply(Message $message, string $subject, string $text, ?string $locale = null): void
    {
        $message->getSymfonyMessage()->html(self::render($subject, $text, $locale));
    }

    public static function render(string $subject, string $text, ?string $locale = null): string
    {
        $settings = GeneralSettings::settings();
        $name = trim((string) ($settings['platformName'] ?? config('app.name', 'PlayHub'))) ?: 'PlayHub';
        $logoText = $name;
        $primary = self::hexColor($settings['defaultPrimaryColor'] ?? data_get($settings, 'design.colors.primary'), '#b68a2d');
        $secondary = self::hexColor($settings['defaultSecondaryColor'] ?? data_get($settings, 'design.colors.primary-2'), '#6f5119');
        $textColor = self::hexColor(data_get($settings, 'design.colors.text'), '#0a1220');
        $mutedColor = self::hexColor(data_get($settings, 'design.colors.muted'), '#657186');
        $softColor = self::mixWithWhite($primary, 0.9);
        $accentText = self::contrastColor($primary);
        $siteUrl = rtrim((string) config('app.url', ''), '/') ?: url('/');
        $preview = trim((string) preg_replace('/\s+/', ' ', $text));
        $preview = function_exists('mb_substr') ? mb_substr($preview, 0, 150) : substr($preview, 0, 150);
        $year = date('Y');
        [$language, $direction] = self::languageAttributes($locale);

        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSubject = $escape($subject);
        $safeLanguage = $escape($language);
        $safeDirection = $escape($direction);
        $safeName = $escape($name);
        $safeLogoText = $escape($logoText);
        $safeUrl = $escape($siteUrl);
        $initial = function_exists('mb_substr') ? mb_substr($logoText, 0, 1) : substr($logoText, 0, 1);
        $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial);
        $safeInitial = $escape($initial);
        $body = self::bodyHtml($text, $primary, $softColor, $textColor, $mutedColor);
        $localization = app(\App\Services\LocalizationService::class);
        $notificationLabel = $escape($localization->translate('Notification', $locale));
        $sentBy = $escape($localization->translate('This email was sent by {site}.', $locale));
        $sentBy = str_replace('{site}', '<a href="'.$safeUrl.'" style="color:'.$primary.';font-weight:700;text-decoration:none;">'.$safeUrl.'</a>', $sentBy);

        return <<<HTML
<!doctype html>
<html lang="{$safeLanguage}" dir="{$safeDirection}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="supported-color-schemes" content="light">
  <title>{$safeSubject}</title>
  <style>
    @media only screen and (max-width:620px){.email-shell{padding:16px 10px!important}.email-card{width:100%!important}.email-header,.email-content,.email-footer{padding-left:22px!important;padding-right:22px!important}.email-title{font-size:25px!important}}
  </style>
</head>
<body dir="{$safeDirection}" style="margin:0;padding:0;background:#f3f5f8;color:{$textColor};font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$escape($preview)}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f5f8;">
    <tr>
      <td class="email-shell" align="center" style="padding:36px 16px;">
        <table role="presentation" class="email-card" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;overflow:hidden;border:1px solid #e2e7ef;border-radius:8px;background:#ffffff;box-shadow:0 12px 34px rgba(15,23,42,.08);">
          <tr><td style="height:6px;background:{$primary};font-size:0;line-height:0;">&nbsp;</td><td width="118" style="height:6px;background:{$secondary};font-size:0;line-height:0;">&nbsp;</td></tr>
          <tr>
            <td class="email-header" colspan="2" style="padding:26px 36px 22px;border-bottom:1px solid #e7ebf1;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td width="42" valign="middle"><div style="width:38px;height:38px;border-radius:7px;background:{$primary};color:{$accentText};font-size:18px;font-weight:800;line-height:38px;text-align:center;">{$safeInitial}</div></td>
                  <td valign="middle" style="padding-left:11px;color:{$textColor};font-size:18px;font-weight:800;line-height:1.2;">{$safeLogoText}</td>
                  <td align="right" valign="middle" style="color:{$mutedColor};font-size:11.5px;font-weight:700;letter-spacing:0;text-transform:uppercase;">{$notificationLabel}</td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="email-content" colspan="2" style="padding:34px 36px 38px;">
              <div style="width:42px;height:4px;margin:0 0 18px;border-radius:4px;background:{$primary};font-size:0;line-height:0;">&nbsp;</div>
              <h1 class="email-title" style="margin:0 0 20px;color:{$textColor};font-size:29px;font-weight:800;line-height:1.2;letter-spacing:0;">{$safeSubject}</h1>
              {$body}
            </td>
          </tr>
          <tr>
            <td class="email-footer" colspan="2" style="padding:22px 36px;border-top:1px solid #e7ebf1;background:#fafbfc;color:{$mutedColor};font-size:12px;line-height:1.6;">
              <strong style="color:{$textColor};">{$safeName}</strong><br>
              <span>{$sentBy}</span><br>
              <span>&copy; {$year} {$safeName}</span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private static function bodyHtml(string $text, string $primary, string $softColor, string $textColor, string $mutedColor): string
    {
        $blocks = preg_split('/\R{2,}/', trim($text)) ?: [];

        return collect($blocks)->map(function (string $block) use ($primary, $softColor, $textColor, $mutedColor): string {
            $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (preg_match('/^(.*?\bcode is:)\s*([0-9]{6})(.*)$/is', $block, $matches)) {
                $intro = nl2br($escape(trim($matches[1])));
                $tail = trim($matches[3]);
                $tailHtml = $tail !== '' ? '<p style="margin:16px 0 0;color:'.$mutedColor.';font-size:14px;line-height:1.7;">'.nl2br($escape($tail)).'</p>' : '';

                return '<p style="margin:0 0 14px;color:'.$textColor.';font-size:15px;line-height:1.7;">'.$intro.'</p>'
                    .'<div style="margin:0 0 20px;border:1px solid '.$primary.';border-radius:7px;background:'.$softColor.';padding:18px 20px;color:'.$primary.';font-family:Consolas,Monaco,monospace;font-size:28px;font-weight:800;letter-spacing:0;text-align:center;">'.$matches[2].'</div>'
                    .$tailHtml;
            }

            $lines = preg_split('/\R/', trim($block)) ?: [];
            if ($lines && collect($lines)->every(fn (string $line): bool => str_starts_with(trim($line), '- '))) {
                $items = collect($lines)->map(fn (string $line): string => '<li style="margin:0 0 8px;padding-left:3px;">'.$escape(substr(trim($line), 2)).'</li>')->implode('');

                return '<ul style="margin:0 0 20px;padding-left:22px;color:'.$textColor.';font-size:15px;line-height:1.65;">'.$items.'</ul>';
            }

            return '<p style="margin:0 0 18px;color:'.$textColor.';font-size:15px;line-height:1.7;">'.nl2br($escape($block)).'</p>';
        })->implode('');
    }

    private static function hexColor(mixed $color, string $fallback): string
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
    }

    private static function mixWithWhite(string $color, float $whiteRatio): string
    {
        $channels = sscanf($color, '#%02x%02x%02x');
        $mixed = array_map(fn (int $channel): int => (int) round($channel + ((255 - $channel) * $whiteRatio)), $channels);

        return sprintf('#%02x%02x%02x', ...$mixed);
    }

    private static function contrastColor(string $color): string
    {
        [$red, $green, $blue] = sscanf($color, '#%02x%02x%02x');
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 255000;

        return $luminance > 0.62 ? '#111827' : '#ffffff';
    }

    private static function languageAttributes(?string $locale): array
    {
        $locale = LanguageLocales::canonical($locale);
        if (! in_array($locale, GeneralSettings::languageIdentifiers(), true)) {
            $locale = strtolower(str_replace('-', '_', (string) (GeneralSettings::settings()['defaultLanguage'] ?? 'en')));
        }
        $locale = preg_match('/^[a-z0-9_-]+$/', $locale) ? $locale : 'en';
        $direction = 'ltr';
        $root = realpath((string) config('playhub.language_path', base_path('lang')));
        $files = $root ? (glob($root.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.php') ?: []) : [];
        if ($files) {
            $translations = require $files[0];
            $direction = data_get($translations, '__meta.direction') === 'rtl' ? 'rtl' : 'ltr';
        }

        return [LanguageLocales::languageTag($locale), $direction];
    }
}
