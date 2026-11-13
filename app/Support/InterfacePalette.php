<?php

namespace App\Support;

/** Paired surface and interaction roles, based on the local WoltLab reference. */
final class InterfacePalette
{
    public const VERSION = 1;

    public static function colors(bool $dark = false, bool $admin = false): array
    {
        $colors = $dark ? [
            'bg' => '#1a1d21', 'panel' => '#222529', 'panel-2' => '#2c313b', 'panel-3' => '#2f394c',
            'text' => '#d1d2d3', 'muted' => '#a3a6aa', 'soft' => '#a3a6aa',
            'line' => '#36373b', 'line-strong' => '#666b73',
            'primary' => '#01579b', 'primary-2' => '#014b84', 'primary-soft' => '#1e3449',
            'link' => '#40afe0', 'focus' => '#40afe0', 'navigation' => '#1e2734',
            'input-bg' => '#1a1d21', 'input-border' => '#666b73',
            'success' => '#62d49a', 'danger' => '#ff8d9d', 'warning' => '#efb65e',
        ] : [
            'bg' => '#fafafa', 'panel' => '#ffffff', 'panel-2' => '#ecf1f7', 'panel-3' => '#e2e9f0',
            'text' => '#3a3a3d', 'muted' => '#62666b', 'soft' => '#62666b',
            'line' => '#d5dce4', 'line-strong' => '#7c8897',
            'primary' => '#1a73b8', 'primary-2' => '#165f99', 'primary-soft' => '#e2edf7',
            'link' => '#2671a6', 'focus' => '#2671a6', 'navigation' => '#ecf1f7',
            'input-bg' => '#f1f6fb', 'input-border' => '#7c8897',
            'success' => '#167342', 'danger' => '#bc2442', 'warning' => '#85520b',
        ];
        if ($admin) {
            $colors['heading'] = $colors['text'];
            $colors['border'] = $colors['line'];
            $colors['border-strong'] = $colors['line-strong'];
            unset($colors['line'], $colors['line-strong']);
        }

        return $colors;
    }

    /** Replace shipped legacy values only; retain deliberately customized roles. */
    public static function migrate(array $stored): array
    {
        if (($stored['design']['colorPaletteVersion'] ?? 0) >= self::VERSION) return $stored;
        $legacyDesign = [
                'colors' => [
                    'bg' => '#edf0f4',
                    'panel' => '#fcfdff',
                    'panel-2' => '#eaeced',
                    'panel-3' => '#e8edf3',
                    'text' => '#28384b',
                    'muted' => '#586478',
                    'soft' => '#586478',
                    'line' => '#d1dae5',
                    'line-strong' => '#d1dae5',
                    'primary' => '#d4b477',
                    'primary-2' => '#bb9e69',
                    'primary-soft' => '#eadabb',
                    'success' => '#22c55e',
                    'danger' => '#e11d48',
                    'warning' => '#f59e0b',
                ],
                'darkColors' => [
                    'bg' => '#142237',
                    'panel' => '#25354b',
                    'panel-2' => '#262d34',
                    'panel-3' => '#19293e',
                    'text' => '#e0e6ed',
                    'muted' => '#a0afc1',
                    'soft' => '#959ea8',
                    'line' => '#3b4d63',
                    'line-strong' => '#3b4d63',
                    'primary' => '#d4b477',
                    'primary-2' => '#bb9e69',
                    'primary-soft' => 'rgba(212,180,119,.22)',
                    'success' => '#35d07f',
                    'danger' => '#fb7185',
                    'warning' => '#f59e0b',
                ],
                'adminColors' => [
                    'bg' => '#edf0f4',
                    'panel' => '#fcfdff',
                    'panel-2' => '#eaeced',
                    'panel-3' => '#e8edf3',
                    'text' => '#28384b',
                    'heading' => '#28384b',
                    'muted' => '#586478',
                    'soft' => '#586478',
                    'border' => '#d1dae5',
                    'border-strong' => '#d1dae5',
                    'primary' => '#d4b477',
                    'primary-2' => '#bb9e69',
                    'primary-soft' => '#eadabb',
                ],
                'adminDarkColors' => [
                    'bg' => '#142237',
                    'panel' => '#25354b',
                    'panel-2' => '#262d34',
                    'panel-3' => '#19293e',
                    'text' => '#e0e6ed',
                    'heading' => '#e0e6ed',
                    'muted' => '#a0afc1',
                    'soft' => '#959ea8',
                    'border' => '#3b4d63',
                    'border-strong' => '#3b4d63',
                    'primary' => '#d4b477',
                    'primary-2' => '#bb9e69',
                    'primary-soft' => 'rgba(212,180,119,.22)',
                ],
        ];
        foreach (['colors', 'darkColors', 'adminColors', 'adminDarkColors'] as $group) {
            $next = self::colors(str_contains($group, 'Dark') || $group === 'darkColors', str_starts_with($group, 'admin'));
            foreach ($next as $role => $value) {
                $previous = $stored['design'][$group][$role] ?? null;
                if ($previous === null || strcasecmp((string) $previous, (string) ($legacyDesign[$group][$role] ?? '')) === 0) {
                    $stored['design'][$group][$role] = $value;
                }
            }
        }
        foreach (['defaultPrimaryColor' => ['#d4b477', '#1a73b8'], 'defaultSecondaryColor' => ['#bb9e69', '#165f99'], 'defaultPrimarySoftColor' => ['#eadabb', '#e2edf7']] as $key => [$old, $next]) {
            if (! isset($stored[$key]) || strcasecmp((string) $stored[$key], $old) === 0) $stored[$key] = $next;
        }
        // The approved platform default changes once; explicit device preferences survive.
        if (! isset($stored['defaultThemeMode']) || $stored['defaultThemeMode'] === 'light') $stored['defaultThemeMode'] = 'system';
        $stored['design']['colorPaletteVersion'] = self::VERSION;

        return $stored;
    }
}
