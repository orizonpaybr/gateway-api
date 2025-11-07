<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'nome_fantasia',
        'razao_social',
        'cartao_cnpj',
        'username',
        'email',
        'password',
        "cpf_cnpj",
        "cpf",
        "data_nascimento",
        "telefone",
        "saldo",
        "total_transacoes",
        "permission",
        "avatar",
        "status",
        "data_cadastro",
        "ip_user",
        "transacoes_aproved",
        "transacoes_recused",
        "valor_sacado",
        "valor_saque_pendente",
        "taxa_cash_in",
        "taxa_cash_out",
        "taxa_cash_in_fixa",
        "taxa_cash_out_fixa",
        "token",
        "banido",
        "aprovado_alguma_vez",
        "cliente_id",
        "taxa_percentual",
        "volume_transacional",
        "valor_pago_taxa",
        "user_id",
        "cep",
        "rua",
        "estado",
        "cidade",
        "bairro",
        "numero_residencia",
        "complemento",
        "foto_rg_frente",
        "foto_rg_verso",
        "selfie_rg",
        "media_faturamento",
        "indicador_ref",
        "whitelisted_ip",
        "ips_saque_permitidos",
        "pushcut_pixpago",
        "twofa_secret",
        "twofa_pin",
        "twofa_enabled",
        "twofa_enabled_at",
        "code_ref",
        "indicador_ref",
        "gerente_id",
        "gerente_percentage",
        "affiliate_id",
        "affiliate_percentage",
        "is_affiliate",
        "affiliate_code",
        "affiliate_link",
        "referral_code",
        "gerente_aprovar",
        "webhook_url",
        "webhook_endpoint",
        "integracao_utmfy",
        "taxas_personalizadas_ativas",
        "taxa_percentual_deposito",
        "taxa_fixa_deposito",
        "valor_minimo_deposito",
        "taxa_percentual_pix",
        "taxa_minima_pix",
        "taxa_fixa_pix",
        "valor_minimo_saque",
        "limite_mensal_pf",
        "taxa_saque_api",
        "taxa_saque_crypto",
        "sistema_flexivel_ativo",
        "valor_minimo_flexivel",
        "taxa_fixa_baixos",
        "taxa_percentual_altos",
        "observacoes_taxas",
        "taxa_flexivel_ativa",
        "taxa_flexivel_valor_minimo",
        "taxa_flexivel_fixa_baixo",
        "taxa_flexivel_percentual_alto",
        "taxa_saque_cripto",
        "preferred_adquirente",
        "adquirente_override",
        "preferred_adquirente_card_billet",
        "adquirente_card_billet_override"
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'affiliate_percentage' => 'decimal:2',
        'is_affiliate' => 'boolean',
        'twofa_enabled' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'twofa_pin',
        'twofa_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
        protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gerente_aprovar' => 'boolean',
            'twofa_enabled' => 'boolean',
            'twofa_enabled_at' => 'datetime',
            "webhook_endpoint" => 'array',
            'taxas_personalizadas_ativas' => 'boolean',
            'sistema_flexivel_ativo' => 'boolean',
            'taxa_percentual_deposito' => 'decimal:2',
            'taxa_fixa_deposito' => 'decimal:2',
            'valor_minimo_deposito' => 'decimal:2',
            'taxa_percentual_pix' => 'decimal:2',
            'taxa_minima_pix' => 'decimal:2',
            'taxa_fixa_pix' => 'decimal:2',
            'valor_minimo_saque' => 'decimal:2',
            'limite_mensal_pf' => 'decimal:2',
            'taxa_saque_api' => 'decimal:2',
            'taxa_saque_crypto' => 'decimal:2',
            'valor_minimo_flexivel' => 'decimal:2',
            'taxa_fixa_baixos' => 'decimal:2',
            'taxa_percentual_altos' => 'decimal:2'
        ];
    }

    public function chaves()
    {
        return $this->belongsTo(UsersKey::class, 'user_id', 'user_id');
    }

    // Relação com o usuário indicado
    public function indicador()
    {
        return $this->belongsTo(User::class, 'indicador_ref', 'code_ref');
    }

    // Relação com os usuários que foram indicados
    public function clientes()
    {
        return $this->hasMany(User::class, 'indicador_ref', 'code_ref');
    }

    public function produtos()
    {
        return $this->hasMany(CheckoutBuild::class);
    }

    public function depositos()
    {
        return $this->hasMany(Solicitacoes::class, 'user_id', 'user_id');
    }

    public function saques()
    {
        return $this->hasMany(SolicitacoesCashOut::class, 'user_id', 'user_id');
    }

    public function comissoes()
    {
        return $this->hasMany(Transactions::class, 'user_id', 'user_id');
    }
    
    /**
     * Relacionamento: usuários que este usuário é gerente
     */
    public function clientesGerente()
    {
        return $this->hasMany(User::class, 'gerente_id');
    }
    
    /**
     * Relacionamento: usuários que este usuário é affiliado
     */
    public function clientesAffiliate()
    {
        return $this->hasMany(User::class, 'affiliate_id');
    }
    
    /**
     * Relacionamento: gerente deste usuário
     */
    public function gerenteAffiliate()
    {
        return $this->belongsTo(User::class, 'gerente_id');
    }
    
    /**
     * Relacionamento: affiliado deste usuário
     */
    public function affiliateUser()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }
    
    /**
     * Gera código único para affiliado
     */
    public function gerarCodigoAffiliate(): string
    {
        if (!$this->affiliate_code) {
            $this->affiliate_code = strtoupper(substr($this->user_id, 0, 4)) . rand(1000, 9999);
            $this->affiliate_link = url('/register') . '?ref=' . $this->affiliate_code;
            $this->save();
        }
        return $this->affiliate_code;
    }
    
    /**
     * Verifica se é affiliado ativo
     */
    public function isAffiliateAtivo(): bool
    {
        return $this->is_affiliate && $this->affiliate_percentage > 0;
    }
}
