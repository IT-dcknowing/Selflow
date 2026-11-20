@extends('admin::gabarits.application')
@section('titre', 'Intégrations de paiement')
@section('topbar_titre', 'Superadmin — Intégrations de paiement')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-plug"></i> Intégrations de paiement</h1>
        <p>Configuration centralisée des passerelles de paiement et suivi des recettes d'abonnement.</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-bottom:22px;">
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Recettes confirmées ce mois</div>
        <div style="font-size:26px;font-weight:800;margin-top:6px;">{{ number_format($revenuMois['total'], 0, ',', ' ') }} F CFA</div>
        <div style="font-size:12px;color:var(--text-3);">Abonnements + réabonnements</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Nouveaux abonnements</div>
        <div style="font-size:26px;font-weight:800;margin-top:6px;">{{ number_format($revenuMois['abonnements'], 0, ',', ' ') }} F CFA</div>
        <div style="font-size:12px;color:var(--text-3);">Paiements confirmés ce mois</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Réabonnements</div>
        <div style="font-size:26px;font-weight:800;margin-top:6px;">{{ number_format($revenuMois['renouvellements'], 0, ',', ' ') }} F CFA</div>
        <div style="font-size:12px;color:var(--text-3);">Paiements confirmés ce mois</div>
    </div>
    <div class="card" style="padding:18px;">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Total sur 12 mois</div>
        <div style="font-size:26px;font-weight:800;margin-top:6px;">{{ number_format($totalPeriode, 0, ',', ' ') }} F CFA</div>
        <div style="font-size:12px;color:var(--text-3);">{{ $nombrePaiements }} paiement(s) confirmé(s)</div>
    </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:22px;">
    <div style="padding:16px 18px;border-bottom:1px solid var(--border);font-weight:800;">Recettes mensuelles — 12 derniers mois</div>
    <table class="table" style="width:100%;min-width:620px;">
        <thead><tr><th>Mois</th><th style="text-align:right;">Abonnements</th><th style="text-align:right;">Réabonnements</th><th style="text-align:right;">Total encaissé</th></tr></thead>
        <tbody>
        @foreach($mois as $ligne)
            <tr>
                <td style="font-weight:700;">{{ $ligne['libelle'] }}</td>
                <td style="text-align:right;">{{ number_format($ligne['abonnements'], 0, ',', ' ') }} F</td>
                <td style="text-align:right;">{{ number_format($ligne['renouvellements'], 0, ',', ' ') }} F</td>
                <td style="text-align:right;font-weight:800;">{{ number_format($ligne['total'], 0, ',', ' ') }} F</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div style="padding:10px 18px;font-size:11px;color:var(--text-3);">Seuls les paiements dont le statut est « confirmé / actif » sont comptabilisés. Les montants sont issus du registre des paiements Selflow.</div>
</div>

<div class="page-header" style="margin-top:28px;">
    <div><h2>Passerelles API</h2><p>Les secrets sont chiffrés en base. Ils ne sont jamais préremplis ni renvoyés dans le HTML.</p></div>
</div>

@foreach($fournisseurs as $code => $libelle)
    @php
        $integration = $integrations->get($code);
        $secrets = $integration?->secretsPresents() ?? ['api_key' => false, 'api_secret' => false, 'webhook_secret' => false];
    @endphp
    <div class="card" style="padding:18px;margin-bottom:14px;">
        <form method="POST" action="{{ route('superadmin.integrations.paiements.enregistrer') }}">
            @csrf
            <input type="hidden" name="fournisseur" value="{{ $code }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                <div style="font-size:16px;font-weight:800;">{{ $libelle }}</div>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;">
                    <input type="hidden" name="actif" value="0">
                    <input type="checkbox" name="actif" value="1" {{ $integration?->actif ? 'checked' : '' }}>
                    Activer cette configuration
                </label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                <div class="form-group">
                    <label class="form-label">Environnement</label>
                    <select name="environnement" class="form-control">
                        <option value="sandbox" @selected(($integration?->environnement ?? 'sandbox') === 'sandbox')>Test / Sandbox</option>
                        <option value="production" @selected(($integration?->environnement ?? 'sandbox') === 'production')>Production</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">URL API</label>
                    <input name="api_url" type="url" class="form-control" value="{{ $integration?->api_url }}" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label">Identifiant marchand</label>
                    <input name="merchant_id" class="form-control" value="{{ $integration?->merchant_id }}" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Client ID</label>
                    <input name="client_id" class="form-control" value="{{ $integration?->client_id }}" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Clé API @if($secrets['api_key']) <span style="color:#059669;">(déjà enregistrée)</span> @endif</label>
                    <input name="api_key" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $secrets['api_key'] ? 'Laisser vide pour conserver' : 'Clé API' }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Secret API @if($secrets['api_secret']) <span style="color:#059669;">(déjà enregistré)</span> @endif</label>
                    <input name="api_secret" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $secrets['api_secret'] ? 'Laisser vide pour conserver' : 'Secret API' }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Secret webhook @if($secrets['webhook_secret']) <span style="color:#059669;">(déjà enregistré)</span> @endif</label>
                    <input name="webhook_secret" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $secrets['webhook_secret'] ? 'Laisser vide pour conserver' : 'Secret webhook' }}">
                </div>
            </div>
            <div class="form-group" style="margin-top:10px;">
                <label class="form-label">Paramètres complémentaires (JSON chiffré en base)</label>
                <textarea name="configuration" class="form-control" rows="3" placeholder='{"cle":"valeur"}'>{{ old('configuration') }}</textarea>
            </div>
            <div style="display:flex;justify-content:flex-end;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Enregistrer de façon sécurisée</button>
            </div>
        </form>
    </div>
@endforeach
@endsection
