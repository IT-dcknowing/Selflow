{{--
    Les négociations d'un côté (client ou fournisseur) — lot 75. Chaque ligne
    mène à la page de la négociation ; le point bleu dit que l'autre partie
    a agi depuis votre dernière visite.
--}}
@php
    $estClient = $role === \App\Modules\Admin\Modeles\B2bNegotiation::CLIENT;
    $routeVoir = $estClient ? 'admin.b2b.client.voir' : 'admin.b2b.fournisseur.voir';
    $tons = [
        'a_faire' => ['#fef3c7', '#92400e'],
        'attente' => ['#eff6ff', '#1d4ed8'],
        'fait'    => ['#ecfdf5', '#047857'],
        'clos'    => ['#f1f5f9', '#64748b'],
    ];
@endphp
<div style="display:flex; gap:4px; margin-bottom:16px; border-bottom:2px solid var(--border);">
    @foreach(['devis' => ['fa-file-alt', 'Demandes de prix', $devis->total()], 'commandes' => ['fa-file-invoice', 'Commandes', $commandes->total()]] as $cle => [$icone, $libelle, $nombre])
        <a href="{{ request()->fullUrlWithQuery(['tab' => $cle]) }}"
           style="padding:10px 20px; font-weight:700; font-size:13px; text-decoration:none; border-bottom:3px solid {{ $tab === $cle ? 'var(--primary)' : 'transparent' }}; color:{{ $tab === $cle ? 'var(--primary)' : 'var(--text-2)' }}; display:inline-flex; align-items:center; gap:7px;">
            <i class="fas {{ $icone }}"></i> {{ $libelle }}
            <span style="background:{{ $tab === $cle ? 'var(--primary)' : 'var(--border)' }}; color:{{ $tab === $cle ? '#fff' : 'var(--text-2)' }}; border-radius:20px; padding:1px 8px; font-size:11px;">{{ $nombre }}</span>
        </a>
    @endforeach
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ $estClient ? 'Fournisseur' : 'Client' }}</th>
                    <th>Référence</th>
                    <th>Articles</th>
                    <th>Étape</th>
                    <th style="text-align:right;">Montant</th>
                    <th style="text-align:right;">Dernière activité</th>
                </tr>
            </thead>
            <tbody>
            @forelse($negociations as $neg)
                @php
                    $autre = $estClient ? $neg->entrepriseFournisseur : $neg->entrepriseClient;
                    [$fond, $encre] = $tons[$neg->tonStatut($role)];
                    $nonLu = $neg->estNonLuPour($role);
                @endphp
                <tr style="cursor:pointer;{{ $nonLu ? ' background:#f8fbff;' : '' }}" onclick="window.location='{{ route($routeVoir, $neg) }}'">
                    <td style="font-weight:{{ $nonLu ? 800 : 600 }};">
                        @if($nonLu)<span title="Nouveau depuis votre dernière visite" style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#2563eb; margin-right:6px;"></span>@endif
                        {{ $autre?->nom }}
                        <div style="font-size:10.5px; color:var(--text-3);">NCC {{ $autre?->ncc }}</div>
                    </td>
                    <td style="font-weight:700; color:var(--primary); font-size:12px;">
                        <a href="{{ route($routeVoir, $neg) }}" style="color:inherit; text-decoration:none;">{{ $neg->reference_commande ?? ('B2B-' . strtoupper(substr($neg->uuid, 0, 6))) }}</a>
                    </td>
                    <td style="font-size:12.5px; color:var(--text-2);">{{ \Illuminate\Support\Str::limit(collect($neg->produits_demandes)->pluck('nom')->join(', '), 50) }}</td>
                    <td><span style="background:{{ $fond }}; color:{{ $encre }}; padding:4px 10px; border-radius:20px; font-weight:700; font-size:11.5px; white-space:nowrap;">{{ $neg->libelleStatut($role) }}</span></td>
                    <td style="text-align:right; font-weight:700;">{{ $neg->prix_final ? number_format((float) $neg->prix_final, 0, ',', ' ') . ' F' : ($neg->totalHt() > 0 ? number_format($neg->totalHt(), 0, ',', ' ') . ' F HT' : '—') }}</td>
                    <td style="text-align:right; font-size:12px; color:var(--text-3);">{{ ($neg->derniere_activite_le ?? $neg->updated_at)?->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-3);">
                    {{ $tab === 'commandes' ? 'Aucune commande B2B.' : 'Aucune demande de prix B2B.' }}
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:0 16px;">{{ $negociations->appends(['tab' => $tab])->links() }}</div>
</div>
