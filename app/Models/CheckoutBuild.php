<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CheckoutBuild extends Model
{
    use HasFactory;

    protected $table = 'checkout_build';

    protected $fillable = [
        'id_unico',
        'user_id',
        'produto_name',
        'produto_descricao',
        'descricao_extra',
        'produto_valor',
        'produto_de_valor',
        'produto_categoria',
        'produto_tipo',
        'produto_tipo_cob',
        'produto_image',
        'whatsapp_suporte',
        'email_suporte',
        'descricao_exta',
        'checkout_color',
        'checkout_color_default',
        'checkout_color_card',
        'checkout_timer_active',
        'checkout_timer_tempo',
        'checkout_timer_cor_fundo',
        'checkout_timer_cor_texto',
        'checkout_timer_texto',
        'checkout_header_logo_active',
        'checkout_header_logo',
        'checkout_header_image_active',
        'checkout_header_image',
        'checkout_banner_active',
        'checkout_banner',
        'checkout_topbar_active',
        'checkout_topbar_text',
        'checkout_topbar_text_color',
        'checkout_topbar_color',
        'checkout_depoimentos_image',
        'checkout_depoimentos_nome',
        'checkout_depoimentos_depoimento',
        'url_pagina_vendas',
        'periodo_garantia',
        'checkout_ads_meta',
        'checkout_ads_google',
        'checkout_ads_tiktok',
        'status',
        'methods'
    ];

    protected $casts = [
        'checkout_timer_active' => 'boolean',
        'checkout_header_logo_active' => 'boolean',
        'checkout_header_image_active' => 'boolean',
        'checkout_topbar_active' => 'boolean',
        'status' => 'boolean',
        'checkout_timer_tempo' => 'integer',
        'methods' => 'array',
    ];

    public $timestamps = true;

    /**
     * Relação com o usuário (User)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bumps()
    {
        return $this->hasMany(CheckoutOrderBump::class, 'checkout_id', 'id');
    }

    public function depoimentos()
    {
        return $this->hasMany(CheckoutDepoimento::class, 'checkout_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(CheckoutOrders::class, 'checkout_id', 'id');
    }
}
