{{--
    La section « Facturation » des paramètres — 15/10/2026.

    L'offre en cours, son échéance, et des cartes : renouveler la sienne avant
    l'échéance, ou passer à une offre supérieure. Le paiement en ligne n'est pas
    encore branché : chaque demande est notée, DC-Knowing confirme le règlement
    et repousse l'échéance.
--}}
@php
    use App\Modules\Admin\Regles\Abonnements;
    $offres = Abonnements::offres();
    $avantages = Abonnements::avantages();
    $actuelle = $entreprise->plan_abonnement;
    $aTerme = Abonnements::estATerme($entreprise);
    $jours = Abonnements::joursRestants($entreprise);
    $renouvelable = in_array($actuelle, [Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE], true);
@endphp
<div class="card" id="section-facturation" style="padding:22px 24px; margin-bottom:22px; {{ $aTerme ? 'border:2px solid #DC2626;' : '' }}">
    <h3 style="font-size:16px; font-weight:800; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
        <span id="facturation" style="scroll-margin-top:90px;"></span>
        <i class="fas fa-file-invoice-dollar" style="color:var(--primary);"></i> Facturation
    </h3>
    <p style="font-size:12.5px; color:var(--text-3); margin-bottom:16px;">Votre offre, son échéance, son renouvellement ou le passage à une offre supérieure.</p>

    @if(session('erreur'))
        <div style="background:#FEF2F2; border:1px solid #FECACA; color:#991B1B; border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:14px;">{{ session('erreur') }}</div>
    @endif

    <div class="facturation-etat" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:18px;">
        <div class="facturation-tuile"><span>Offre actuelle</span><strong>{{ Abonnements::libelle($actuelle) }}</strong></div>
        <div class="facturation-tuile"><span>Échéance</span>
            <strong style="{{ $aTerme ? 'color:#DC2626;' : '' }}">
                @if($entreprise->abonnement_expire_le)
                    {{ $entreprise->abonnement_expire_le->format('d/m/Y') }}
                    <small style="display:block; font-size:11px; font-weight:600;">{{ $aTerme ? 'Abonnement à terme' : $jours . ' jour' . ($jours > 1 ? 's' : '') . ' restant' . ($jours > 1 ? 's' : '') }}</small>
                @else
                    Sans échéance
                @endif
            </strong>
        </div>
        <div class="facturation-tuile"><span>Points de vente</span><strong>{{ $entreprise->pointsDeVente()->count() }} / {{ Abonnements::enChiffres($entreprise->quota_points_de_vente) }}</strong></div>
        <div class="facturation-tuile"><span>Utilisateurs</span><strong>{{ $entreprise->utilisateurs()->count() }} / {{ Abonnements::enChiffres($entreprise->quota_utilisateurs) }}</strong></div>
    </div>

    @php $dernierPaiement = \App\Modules\Admin\Modeles\PaiementAbonnement::where('entreprise_id', $entreprise->id)->latest()->first(); @endphp
    @if($dernierPaiement)
        @php [$fondP, $texteP] = $dernierPaiement->couleurs(); @endphp
        <div style="border:1px solid var(--border); border-radius:10px; padding:10px 14px; font-size:12.5px; margin-bottom:16px;">
            <strong>Dernier paiement</strong> — {{ \App\Modules\Admin\Regles\Abonnements::libelle($dernierPaiement->offre) }},
            {{ number_format((float) $dernierPaiement->montant, 0, ',', ' ') }} F, référence {{ $dernierPaiement->reference_paiement }} :
            <span style="font-weight:700; padding:2px 8px; border-radius:20px; background:{{ $fondP }}; color:{{ $texteP }};">{{ $dernierPaiement->libelleStatut() }}</span>
            <span style="color:var(--text-3);">
                @foreach($dernierPaiement->etapes() as $etape) · {{ $etape['libelle'] }} {{ $etape['le']?->format('d/m/Y H:i') }} @endforeach
            </span>
            @if($dernierPaiement->statut === 'echec' && $dernierPaiement->motif_echec)<div style="color:#b91c1c; margin-top:4px;">Motif : {{ $dernierPaiement->motif_echec }}</div>@endif
        </div>
    @endif

    @if($entreprise->abonnement_demande)
        <div style="background:#EFF6FF; border:1px solid #BFDBFE; color:#1E40AF; border-radius:10px; padding:10px 14px; font-size:12.5px; margin-bottom:16px;">
            <i class="fas fa-hourglass-half"></i>
            Demande « {{ Abonnements::libelle($entreprise->abonnement_demande) }} » du {{ $entreprise->abonnement_demande_le?->format('d/m/Y') }} :
            en attente du règlement. Le paiement en ligne arrive bientôt ; DC-Knowing confirme d'ici là.
        </div>
    @endif

    <div class="facturation-cartes">
        @foreach([Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE] as $code)
            @php
                $offre = $offres[$code];
                $estActuelle = $actuelle === $code;
                $superieure = Abonnements::estSuperieure($code, $actuelle);
            @endphp
            <div class="facturation-carte {{ $estActuelle ? 'actuelle' : '' }} {{ !$estActuelle && !$superieure ? 'inferieure' : '' }}" data-offre="{{ $code }}">
                @if($estActuelle)<span class="facturation-ruban">Votre offre</span>@endif
                <h4>{{ $offre['nom'] }}</h4>
                <div class="facturation-prix">
                    @if($offre['prix'] === null) Sur devis @else {{ number_format($offre['prix'], 0, ',', ' ') }} F <small>/ mois</small> @endif
                </div>
                @if($offre['points_de_vente'] !== null)
                    <div class="facturation-limites">
                        {{ Abonnements::enChiffres($offre['points_de_vente']) }} point{{ $offre['points_de_vente'] > 1 ? 's' : '' }} de vente ·
                        {{ Abonnements::enChiffres($offre['utilisateurs']) }} utilisateur{{ $offre['utilisateurs'] > 1 ? 's' : '' }}
                    </div>
                @endif
                <ul>
                    @foreach($avantages[$code] as $avantage)
                        <li><i class="fas fa-check"></i> {{ $avantage }}</li>
                    @endforeach
                </ul>

                @if($estActuelle && $renouvelable)
                    <form method="POST" action="{{ route('admin.abonnement.renouveler') }}">
                        @csrf
                        <input type="hidden" name="depuis" value="facturation">
                        {{-- Toutes les offres renouvelables, sur mesure comprise, se
                             règlent par référence de transaction (16/10/2026). --}}
                        <button type="button" class="btn btn-primary" style="width:100%; justify-content:center;"
                                onclick="var c = this.nextElementSibling; c.style.display = c.style.display === 'none' ? 'block' : 'none';"><i class="fas fa-rotate"></i> Renouveler</button>
                        @include('admin::abonnements.partials.champs-paiement', ['montant' => ($offre['prix'] ?? 0) > 0 ? $offre['prix'] : null])
                    </form>
                @elseif($superieure)
                    <form method="POST" action="{{ route('admin.abonnement.enregistrer') }}">
                        @csrf
                        <input type="hidden" name="depuis" value="facturation">
                        <input type="hidden" name="offre" value="{{ $code }}">
                        @if(($offre['prix'] ?? 0) > 0)
                            <button type="button" class="btn btn-primary" style="width:100%; justify-content:center;"
                                    onclick="var c = this.nextElementSibling; c.style.display = c.style.display === 'none' ? 'block' : 'none';">Passer à {{ $offre['nom'] }}</button>
                            @include('admin::abonnements.partials.champs-paiement', ['montant' => $offre['prix']])
                        @else
                            <button type="submit" class="btn btn-outline" style="width:100%; justify-content:center;">Être contacté</button>
                        @endif
                    </form>
                @else
                    <button type="button" class="btn btn-outline" disabled style="width:100%; justify-content:center; opacity:.55;">Offre inférieure</button>
                @endif
            </div>
        @endforeach
    </div>
</div>

<style>
    .facturation-tuile { background:var(--bg3, #F1F5F9); border-radius:10px; padding:10px 14px; }
    .facturation-tuile span { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-3); }
    .facturation-tuile strong { font-size:15px; }
    .facturation-cartes { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; }
    .facturation-carte { position:relative; border:1px solid var(--border); border-radius:14px; padding:18px 16px; display:flex; flex-direction:column; gap:8px; background:var(--bg2, #fff); }
    .facturation-carte.actuelle { border:2px solid var(--primary); background:#F8FBFF; }
    .facturation-carte.inferieure { opacity:.65; }
    .facturation-carte h4 { font-size:12.5px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--primary); }
    .facturation-prix { font-size:22px; font-weight:800; }
    .facturation-prix small { font-size:12px; color:var(--text-3); font-weight:600; }
    .facturation-limites { font-size:12px; color:var(--text-2); background:var(--bg3, #F1F5F9); border-radius:8px; padding:6px 10px; }
    .facturation-carte ul { list-style:none; font-size:12.5px; line-height:1.8; flex:1; margin:4px 0 6px; }
    .facturation-carte li i { color:#22c55e; margin-right:5px; }
    .facturation-ruban { position:absolute; top:-10px; right:14px; background:var(--primary); color:#fff; font-size:10.5px; font-weight:700; padding:2px 10px; border-radius:20px; }
</style>
