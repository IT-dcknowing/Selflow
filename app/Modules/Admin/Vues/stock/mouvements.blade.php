@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\MouvementStock', 'Mvt')
@section('titre', 'Mouvements de stock')
@section('topbar_titre', 'Stock — Mouvements')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-arrows-up-down"></i> Mouvements de stock</h1>
        <p>Historique de toutes les entrées, sorties, transferts et rebuts.</p>
    </div>
    <a href="{{ route('admin.stock.index') }}" class="btn btn-outline">
        <i class="fas fa-boxes-stacked"></i> Retour à l'inventaire
    </a>
</div>

{{-- Onglets / Sections (Style Odoo) --}}
<div style="display:flex; border-bottom:2px solid var(--border); margin-bottom:20px; gap:8px;">
    @php
        $sections = [
            'tous'        => ['label' => 'Tous les mouvements', 'icon' => 'fa-list'],
            'achats'      => ['label' => 'Réceptions (Achats)', 'icon' => 'fa-truck-loading'],
            'ventes'      => ['label' => 'Livraisons (Ventes)', 'icon' => 'fa-dolly'],
            'transferts'  => ['label' => 'Transferts Internes',  'icon' => 'fa-exchange-alt'],
            'entrees'     => ['label' => 'Entrées de stock',     'icon' => 'fa-dolly'],
            'rebuts'      => ['label' => 'Rebuts & Pertes',     'icon' => 'fa-trash-can'],
        ];
    @endphp
    @foreach($sections as $key => $sec)
        <a href="{{ route('admin.stock.mouvements', ['section' => $key]) }}"
           style="text-decoration:none; display:flex; align-items:center; gap:8px; padding:12px 20px; font-size:13px; font-weight:700; border-bottom:3px solid {{ $section === $key ? 'var(--primary)' : 'transparent' }}; color:{{ $section === $key ? 'var(--primary)' : 'var(--text-3)' }}; transition:all .2s;">
            <i class="fas {{ $sec['icon'] }}"></i> {{ $sec['label'] }}
        </a>
    @endforeach
</div>

<div class="card">
    <div class="table-wrap">
        @if($mouvements->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-arrows-up-down" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            Aucun mouvement enregistré pour cette section.
        </div>
        @else
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Produit</th>
                    <th>Mouvement</th>
                    <th>Quantité</th>
                    <th>Stock (Avant/Après)</th>
                    <th>Qui (Opérateur)</th>
                    <th>Provenance / Destination</th>
                    <th>Point de vente</th>
                    <th>Doc. Réf</th>
                </tr>
            </thead>
            <tbody>
                @foreach($mouvements as $m)
                <tr>
                    <td style="color:var(--text-3); font-size:12px;">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        <div style="font-weight:600;">{{ $m->produit->nom }}</div>
                        <div style="font-size:11px; color:var(--text-3); font-family:monospace;">{{ $m->produit->reference }}</div>
                    </td>
                    <td>
                        @php
                            $subColors = [
                                Mvt::RECEPTION               => ['bg' => '#ecfdf5', 'color' => '#047857', 'label' => 'Réception'],
                                Mvt::LIVRAISON               => ['bg' => '#eff6ff', 'color' => '#1d4ed8', 'label' => 'Livraison'],
                                Mvt::TRANSFERT               => ['bg' => '#fff7ed', 'color' => '#b45309', 'label' => 'Transfert'],
                                Mvt::REBUT                   => ['bg' => '#fef2f2', 'color' => '#b91c1c', 'label' => 'Rebut'],
                                Mvt::INVENTAIRE              => ['bg' => '#f3f4f6', 'color' => '#374151', 'label' => 'Inventaire'],
                                Mvt::PRODUCTION_ENTREE       => ['bg' => '#f5f3ff', 'color' => '#6d28d9', 'label' => 'Production'],
                                Mvt::PRODUCTION_CONSOMMATION => ['bg' => '#f5f3ff', 'color' => '#6d28d9', 'label' => 'Consommation'],
                                Mvt::CONTREPASSATION         => ['bg' => '#fffbeb', 'color' => '#92400e', 'label' => 'Contre-passation'],
                                Mvt::STOCK_INITIAL           => ['bg' => '#ecfdf5', 'color' => '#047857', 'label' => 'Stock d\'ouverture'],
                                Mvt::ENTREE_DIVERSE          => ['bg' => '#ecfdf5', 'color' => '#047857', 'label' => 'Entrée de stock'],
                            ];
                            $sc = $subColors[$m->sous_type] ?? ['bg' => '#f3f4f6', 'color' => '#374151', 'label' => $m->type_mouvement];
                        @endphp
                        <span class="badge" style="background:{{ $sc['bg'] }}; color:{{ $sc['color'] }}; padding:4px 10px; border-radius:20px; font-weight:700; font-size:11px;">
                            {{ $sc['label'] }}
                        </span>
                    </td>
                    @php $estEntree = $m->type_mouvement === Mvt::ENTREE; @endphp
                    <td style="font-weight:700; {{ $estEntree ? 'color:var(--success)' : 'color:var(--danger)' }}">
                        {{ $estEntree ? '+' : '-' }}@qte($m->quantite)
                        <span style="font-size:11px; font-weight:400; color:var(--text-3);">{{ $m->produit->unite }}</span>
                    </td>
                    <td>
                        <div style="font-size:13px; font-weight:600; color:var(--text-1);">
                            @qte($m->stock_apres)
                            <span style="font-weight:400; font-size:11px; color:var(--text-3);"> (avant: @qte($m->stock_avant))</span>
                        </div>
                    </td>
                    <td>
                        @if($m->utilisateur)
                            <div style="font-weight:600; font-size:13px;">{{ $m->utilisateur->nom }}</div>
                            <div style="font-size:11px; color:var(--text-3);">{{ ucfirst($m->utilisateur->role) }}</div>
                        @else
                            <span style="color:var(--text-3); font-style:italic;">Système</span>
                        @endif
                    </td>
                    <td style="font-size:13px; color:var(--text-2);">
                        @if($m->sous_type === Mvt::RECEPTION && $m->fournisseur)
                            <i class="fas fa-building" style="font-size:11px; color:var(--text-3);"></i> {{ $m->fournisseur->nom }}
                        @elseif($m->sous_type === Mvt::LIVRAISON && $m->client)
                            <i class="fas fa-user" style="font-size:11px; color:var(--text-3);"></i> {{ $m->client->nom }}
                        @elseif($m->sous_type === Mvt::TRANSFERT)
                            @if($estEntree)
                                <i class="fas fa-arrow-left-to-bracket" style="color:var(--success); font-size:11px;"></i> Depuis: <strong>{{ $m->pointDeVenteContrepartie->nom ?? 'Autre site' }}</strong>
                            @else
                                <i class="fas fa-arrow-right-from-bracket" style="color:var(--danger); font-size:11px;"></i> Vers: <strong>{{ $m->pointDeVenteContrepartie->nom ?? 'Autre site' }}</strong>
                            @endif
                        @elseif($m->sous_type === Mvt::ENTREE_DIVERSE)
                            {{ Mvt::RAISONS_ENTREE[$m->raison_entree] ?? 'Entrée de stock' }}@if($m->commentaire) — {{ $m->commentaire }}@endif
                        @elseif($m->sous_type === Mvt::REBUT)
                            <span style="color:var(--danger); font-style:italic;"><i class="fas fa-trash-can"></i> Mis au rebut</span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-purple" style="font-size:11px; font-weight:600;"><i class="fas fa-store"></i> {{ $m->pointDeVente->nom }}</span>
                    </td>
                    <td style="font-family:monospace; font-size:12px; color:var(--primary); font-weight:600;">
                        {{ $m->reference_document ?? '—' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding:16px 20px; border-top:1px solid var(--border);">
            {{ $mouvements->appends(['section' => $section])->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
