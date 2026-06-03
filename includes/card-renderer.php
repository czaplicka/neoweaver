<?php
/**
 * card-renderer.php — centralna funkcja renderująca kartę NeoWeaver
 *
 * Struktura HTML karty:
 *   .nw-card .nw-card--{rarity}              ← szkielet z cards.css
 *     .nw-card__ascension                    ← dotki ascension (cards.css)
 *     .nw-card__type-icon                    ← ikona kategorii (cards.css)
 *     img                                    ← obrazek
 *     .nw-card__header                       ← nagłówek (cards.css)
 *     .nw-card__body                         ← ciało (cards.css)
 *       .nw-asc-dots          (tylko ascension mode)
 *       .nw-asc-bonuses       (tylko ascension mode)
 *       .nw-asc-progress-wrap (tylko ascension mode)
 *     .nw-card__footer        ← przyciski
 *
 * Używana przez library.php i ascension.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'nw_render_card' ) ) :

function nw_render_card( array $card, string $mode = 'library' ): string {

    /* ── rarity ─────────────────────────────────────────────── */
    $rarity_map = [
        'common'    => 'nw-card--common',
        'uncommon'  => 'nw-card--uncommon',
        'rare'      => 'nw-card--rare',
        'epic'      => 'nw-card--epic',
        'legendary' => 'nw-card--legendary',
    ];
    $rarity_colors = [
        'common'    => '#8a9bb0',
        'uncommon'  => '#4ade80',
        'rare'      => '#60a5fa',
        'epic'      => '#c084fc',
        'legendary' => '#fbbf24',
    ];

    $rarity     = strtolower( $card['rarity'] ?? 'common' );
    $rarity_cls = $rarity_map[ $rarity ] ?? 'nw-card--common';

    /* ── podstawowe dane ───────────────────────────────────── */
    $iid       = esc_attr( (string) ( $card['instance_id'] ?? '' ) );
    $name      = esc_html( $card['name'] ?? '' );
    $desc      = esc_html( $card['description'] ?? '' );
    $effect    = esc_html( $card['effect'] ?? '' );
    $cat_label = esc_html( $card['cat_label'] ?? '' );
    $cat_icon  = esc_attr( $card['cat_icon'] ?? 'tag' );
    $cat_color = esc_attr( $card['cat_color'] ?? '#adff00' );

    /* ── tryb: library ─────────────────────────────────────── */
    if ( 'library' === $mode ) {

        $location   = $card['location'] ?? 'library';
        $active_cls = ( 'active' === $location ) ? 'nw-card--ready' : '';
        $loc        = esc_attr( $location );
        $level      = (int) ( $card['level'] ?? 1 );

        $rarity_color = esc_attr( ( 'active' === $location )
            ? '#adff00'
            : ( $rarity_colors[ $rarity ] ?? '#8a9bb0' )
        );

        $classes     = trim( implode( ' ', array_filter( [ 'nw-card', $rarity_cls, $active_cls ] ) ) );
        $extra_attrs = "draggable=\"true\" data-card-location=\"{$loc}\"";
        $body_extra  = '';
        $level_html  = "<span class=\"nw-card__level\">LVL&nbsp;{$level}</span>";

        $action_label = ( 'active' === $location ) ? 'Remove' : 'Add to Deck';
        $action_icon  = ( 'active' === $location ) ? 'minus-circle' : 'plus-circle';
        $btn_cls      = ( 'active' === $location ) ? 'nw-asc-btn--ready' : 'nw-asc-btn--locked';

        $footer_html = <<<HTML
<div class="nw-card__footer">
    <button class="nw-asc-btn {$btn_cls} nw-lib-toggle"
        data-instance-id="{$iid}"
        data-location="{$loc}">
        <i data-lucide="{$action_icon}" style="width:11px;height:11px;vertical-align:middle;"></i>
        {$action_label}
    </button>
</div>
HTML;

    /* ── tryb: ascension ───────────────────────────────────── */
    } elseif ( 'ascension' === $mode ) {

        $cur_asc     = (int) ( $card['cur_asc'] ?? 0 );
        $next_asc    = (int) ( $card['next_asc'] ?? 1 );
        $base_count  = (int) ( $card['base_count'] ?? 0 );
        $required    = (int) ( $card['required'] ?? 999 );
        $can_ascend  = (bool) ( $card['can_ascend'] ?? false );
        $maxed       = (bool) ( $card['maxed'] ?? false );
        $all_bonuses = $card['all_bonuses'] ?? [];
        $did         = esc_attr( (string) ( $card['deck_id'] ?? '' ) );
        $nonce       = esc_attr( $card['nonce'] ?? '' );
        $state_cls   = esc_attr( $card['state_cls'] ?? '' );

        $rarity_color = esc_attr( $can_ascend
            ? '#adff00'
            : ( $rarity_colors[ $rarity ] ?? '#8a9bb0' )
        );

        $classes     = trim( implode( ' ', array_filter( [ 'nw-card', $rarity_cls, $state_cls ] ) ) );
        $extra_attrs = '';

        $level_html = $cur_asc > 0
            ? "<span class=\"nw-card__level\"><i data-lucide=\"chevrons-up\" style=\"width:9px;height:9px;\"></i>&nbsp;ASC&nbsp;{$cur_asc}</span>"
            : "<span class=\"nw-card__level\">LVL&nbsp;" . (int)($card['level']??1) . "</span>";

        /* dotki */
        $dots = '';
        for ( $i = 1; $i <= 5; $i++ ) {
            $lit   = $i <= $cur_asc ? ' nw-asc-dot--lit' : '';
            $dots .= "<span class=\"nw-asc-dot{$lit}\"></span>";
        }
        $body_extra = "<div class=\"nw-asc-dots\">{$dots}</div>";

        /* aktywne bonusy */
        if ( $cur_asc > 0 && ! empty( $all_bonuses ) && function_exists( '_nw_asc_render_bonuses' ) ) {
            $body_extra .= _nw_asc_render_bonuses( $all_bonuses, $cur_asc );
        }

        /* preview następnego poziomu */
        if ( ! $maxed && ! empty( $all_bonuses[ (string) $next_asc ] ) ) {
            $bonus_icons = [
                'damage' => 'sword', 'defense' => 'shield', 'xp_gain' => 'star',
                'hp' => 'heart', 'speed' => 'zap', 'crit' => 'crosshair',
                'special' => 'sparkles', 'unlock_effect' => 'unlock',
            ];
            $pills = '';
            foreach ( $all_bonuses[ (string) $next_asc ] as $bonus ) {
                if ( ! is_array( $bonus ) ) continue;
                $type  = (string) ( $bonus['type'] ?? 'bonus' );
                $value = $bonus['value'] ?? null;
                $icon  = esc_attr( $bonus_icons[ $type ] ?? 'plus' );
                $label = is_numeric( $value )
                    ? esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ' +' . esc_html( $value )
                    : esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . ( $value !== null ? ': ' . esc_html( $value ) : '' );
                $pills .= "<span class=\"nw-asc-bonus-pill nw-asc-bonus-pill--next\"><i data-lucide=\"{$icon}\" style=\"width:10px;height:10px;vertical-align:middle;\"></i> {$label}</span>";
            }
            $asc_label   = esc_html( "ASC {$next_asc} unlocks:" );
            $body_extra .= <<<HTML
<div class="nw-asc-bonuses-preview">
    <span class="nw-asc-preview-label">
        <i data-lucide="chevron-right" style="width:10px;height:10px;vertical-align:middle;"></i>
        {$asc_label}
    </span>
    {$pills}
</div>
HTML;
        }

        /* progress bar */
        if ( ! $maxed ) {
            $pct          = $required > 0 ? min( 100, round( $base_count / $required * 100 ) ) : 100;
            $copies_label = esc_html( "{$base_count} / {$required} copies" );
            $next_label   = esc_html( "→ ASC {$next_asc}" );
            $body_extra  .= <<<HTML
<div class="nw-asc-progress-wrap">
    <div class="nw-asc-progress-bar">
        <div class="nw-asc-progress-fill" style="width:{$pct}%"></div>
    </div>
    <div class="nw-asc-progress-label">
        <span>{$copies_label}</span>
        <span>{$next_label}</span>
    </div>
</div>
HTML;
        }

        /* footer button */
        if ( $maxed ) {
            $btn = '<span class="nw-asc-btn nw-asc-btn--max">MAX ⬡</span>';
        } elseif ( $can_ascend ) {
            $btn = <<<HTML
<button class="nw-asc-btn nw-asc-btn--ready nw-asc-trigger"
    data-deck-id="{$did}"
    data-nonce="{$nonce}">
    <i data-lucide="zap" style="width:11px;height:11px;vertical-align:middle;"></i>
    Ascend → Tier {$next_asc}
</button>
HTML;
        } else {
            $need = $required - $base_count;
            $btn  = <<<HTML
<button class="nw-asc-btn nw-asc-btn--locked" disabled>
    <i data-lucide="lock" style="width:11px;height:11px;vertical-align:middle;"></i>
    Need {$need} more
</button>
HTML;
        }
        $footer_html = "<div class=\"nw-card__footer\">{$btn}</div>";

    } else {
        return '';
    }

    /* ── wspólne elementy wizualne ─────────────────────────── */

    $img_html = '';
    if ( ! empty( $card['img_url'] ) ) {
        $src      = esc_url( $card['img_url'] );
        $img_html = <<<HTML
<div class="nw-card__img-wrap">
    <img src="{$src}" alt="{$name}" loading="lazy" width="200" height="120">
    <div class="nw-asc-img-overlay"></div>
</div>
HTML;
    }

    /* górna belka z dotkami (cards.css) */
    $asc_rank = ( 'ascension' === $mode )
        ? (int) ( $card['cur_asc'] ?? 0 )
        : (int) ( $card['asc_rank'] ?? 0 );
    $asc_bar  = "<div class=\"nw-card__ascension\" data-rank=\"{$asc_rank}\" data-max=\"5\"></div>";

    /* ikona kategorii */
    $type_icon = '';
    if ( $cat_label !== '' ) {
        $type_icon = <<<HTML
<div class="nw-card__type-icon" title="{$cat_label}" style="color:{$cat_color};">
    <i data-lucide="{$cat_icon}"></i>
</div>
HTML;
    }

    $effect_html = $effect !== '' ? "<p class=\"nw-card__effect\">{$effect}</p>" : '';
    $extra       = $extra_attrs ? " {$extra_attrs}" : '';

    return <<<HTML
<div class="{$classes}"
     style="--nw-rarity-color:{$rarity_color}; --nw-cat-color:{$cat_color};"
     data-instance-id="{$iid}"{$extra}>

    {$asc_bar}
    {$type_icon}
    {$img_html}

    <div class="nw-card__header">
        <span class="nw-card__name">{$name}</span>
        {$level_html}
    </div>

    <div class="nw-card__body">
        <p class="nw-card__desc">{$desc}</p>
        {$effect_html}
        {$body_extra}
    </div>

    {$footer_html}

</div>
HTML;
}

endif;
