{{-- « Stock renouvelé » : l'avis que DC-Knowing a terminé une demande de
     stickers (16/10/2026). Il reste affiché jusqu'à « J'ai compris ». --}}
@php
    $__avis = null;
    $__u = auth()->user();
    if ($__u && !$__u->estSuperAdmin() && $__u->entreprise_id && $__u->aHabilitation('stickers_commande')) {
        $__avis = \App\Modules\Admin\Modeles\DemandeSticker::where('entreprise_id', $__u->entreprise_id)
            ->where('statut', 'achetee')->whereNull('notification_vue_le')->latest('achetee_le')->first();
    }
@endphp
@if($__avis)
<div id="avis-stickers" style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; background:#ecfdf5; border:1px solid #86efac; color:#14532d; border-radius:12px; padding:14px 18px; margin-bottom:16px;">
    <div style="display:flex; gap:12px; align-items:center;">
        <i class="fas fa-ticket" style="font-size:20px; color:#16a34a;"></i>
        <div>
            <strong style="font-size:14px;">Stock de stickers renouvelé</strong>
            <div style="font-size:12.5px;">
                @if($__avis->stock_communique !== null || $__avis->solde_communique !== null)
                    {{ number_format($__avis->quantite, 0, ',', ' ') }} stickers achetés pour vous.
                    @if($__avis->stock_communique !== null) Stock disponible : <strong>{{ number_format($__avis->stock_communique, 0, ',', ' ') }} stickers</strong>. @endif
                    @if($__avis->solde_communique !== null) Solde disponible : <strong>{{ number_format((float) $__avis->solde_communique, 0, ',', ' ') }} F</strong>. @endif
                @else
                    {{ number_format($__avis->quantite, 0, ',', ' ') }} stickers achetés pour vous : vous pouvez normaliser.
                    Le stock et le solde seront à jour après votre première normalisation.
                @endif
            </div>
        </div>
    </div>
    <form method="POST" action="{{ route('admin.stickers.avis_vu', $__avis) }}" style="margin:0;">
        @csrf
        <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; border:none;">J'ai compris</button>
    </form>
</div>
@endif
