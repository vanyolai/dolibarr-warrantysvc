<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * Shipment-centric warranty overview.
 * Individual svc_warranty rows remain the source of truth but are grouped here
 * by shipment for day-to-day use and warranty-letter creation.
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/warrantysvc/class/svcwarrantyletter.class.php');

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'sendings', 'orders'));

if (!$user->hasRight('warrantysvc', 'svcwarranty', 'read')) accessforbidden();

$socid = GETPOSTINT('socid');
$preset = GETPOST('preset', 'aZ09');
$searchShipment = GETPOST('search_shipment', 'restricthtml');
$searchCompany = GETPOST('search_company', 'restricthtml');
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'e.date_expedition';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
if (!in_array(strtoupper($sortorder), array('ASC','DESC'), true)) $sortorder = 'DESC';
$limit = $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? GETPOSTINT('pageplusone') - 1 : max(0, GETPOSTINT('page'));
$offset = $page * $limit;

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $searchShipment = '';
    $searchCompany = '';
    $preset = '';
}

$listparam = '';
if ($socid > 0) $listparam .= '&socid='.((int) $socid);
if ($preset !== '') $listparam .= '&preset='.urlencode($preset);
if ($searchShipment !== '') $listparam .= '&search_shipment='.urlencode($searchShipment);
if ($searchCompany !== '') $listparam .= '&search_company='.urlencode($searchCompany);

$sqlFrom = ' FROM '.MAIN_DB_PREFIX.'svc_warranty w';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'expedition e ON e.rowid = w.fk_expedition';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = w.fk_soc';
$sqlFrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment ls';
$sqlFrom .= ' ON ls.entity = w.entity AND ls.fk_expedition = w.fk_expedition';
$sqlFrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter l ON l.rowid = ls.fk_letter AND l.entity = ls.entity';
$sqlWhere = ' WHERE w.entity = '.((int) $conf->entity);
$sqlWhere .= " AND w.status <> '".SvcWarranty::STATUS_VOIDED."' AND w.fk_expedition IS NOT NULL AND w.fk_expedition > 0";
if ($socid > 0) $sqlWhere .= ' AND w.fk_soc = '.((int) $socid);
if ($searchShipment !== '') $sqlWhere .= natural_search('e.ref', $searchShipment);
if ($searchCompany !== '') $sqlWhere .= natural_search('s.nom', $searchCompany);
if ($preset === 'withoutletter') $sqlWhere .= ' AND ls.fk_letter IS NULL';

$sqlCount = 'SELECT COUNT(*) AS nb FROM (SELECT w.fk_expedition'.$sqlFrom.$sqlWhere.' GROUP BY w.fk_expedition) x';
$nbtotalofrecords = 0;
$resCount = $db->query($sqlCount);
if ($resCount && ($obj = $db->fetch_object($resCount))) $nbtotalofrecords = (int) $obj->nb;
if ($resCount) $db->free($resCount);

$sql = 'SELECT e.rowid AS shipment_id, e.ref AS shipment_ref, e.date_expedition, w.fk_soc, s.nom AS company_name,';
$sql .= ' SUM(w.covered_qty) AS covered_qty,';
$sql .= ' MIN(w.expiry_date) AS warranty_expiry_min, MAX(w.expiry_date) AS warranty_expiry_max,';
$sql .= ' ls.fk_letter, l.ref AS letter_ref, l.status AS letter_status';
$sql .= $sqlFrom.$sqlWhere;
$sql .= ' GROUP BY e.rowid, e.ref, e.date_expedition, w.fk_soc, s.nom, ls.fk_letter, l.ref, l.status';
$allowedSort = array(
    'e.date_expedition'=>'e.date_expedition',
    'e.ref'=>'e.ref',
    's.nom'=>'s.nom',
    'covered_qty'=>'covered_qty',
    'warranty_expiry_max'=>'warranty_expiry_max'
);
if (!isset($allowedSort[$sortfield])) {
    $sortfield = 'e.date_expedition';
}
$sql .= $db->order($allowedSort[$sortfield], $sortorder);
$sql .= $db->plimit($limit, $offset);

llxHeader('', $langs->trans('WarrantyShipments'));

if ($socid > 0) {
    require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
    require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
    $soc = new Societe($db);
    if ($soc->fetch($socid) > 0) {
        $head = societe_prepare_head($soc);
        print dol_get_fiche_head($head, 'warrantysvc_warranties', $langs->trans('ThirdParty'), -1, 'company');
        dol_banner_tab($soc, 'socid', '', 0, 'rowid', 'nom');
        print dol_get_fiche_end();
        print '<br>';
    }
}

print_barre_liste(
    $langs->trans('WarrantyShipments'),
    $page,
    $_SERVER['PHP_SELF'],
    $listparam,
    $sortfield,
    $sortorder,
    '',
    $nbtotalofrecords,
    $nbtotalofrecords,
    'shipment',
    0,
    '',
    '',
    $limit
);

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('WarrantyShipmentViewHelp').'</div>';

$canCreateLetter = $user->hasRight('warrantysvc', 'warrantyletter', 'write');

if ($canCreateLetter) {
    print '<form method="POST" action="'.dol_buildpath('/warrantysvc/warranty_letter_card.php',1).'" id="warrantyShipmentCreateForm">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="create_letter">';
    print '</form>';
}

print '<form method="GET" id="shipmentSearchForm" action="'.$_SERVER['PHP_SELF'].'">';
if ($socid > 0) print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
if ($preset !== '') print '<input type="hidden" name="preset" value="'.dol_escape_htmltag($preset).'">';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';

print '<tr class="liste_titre_filter">';
if ($canCreateLetter) print '<td class="center"></td>';
print '<td><input class="flat maxwidth100" type="text" name="search_shipment" value="'.dol_escape_htmltag($searchShipment).'"></td>';
print '<td><input class="flat maxwidth150" type="text" name="search_company" value="'.dol_escape_htmltag($searchCompany).'" form="shipmentSearchForm"></td>';
print '<td></td><td></td><td></td><td></td>';
print '<td class="right">';
print '<input class="button small" type="submit" name="button_search_x" value="'.$langs->trans('Search').'">';
print ' <input class="button small" type="submit" name="button_removefilter_x" value="'.$langs->trans('Reset').'">';
print '</td></tr>';

print '<tr class="liste_titre">';
if ($canCreateLetter) print '<th class="center"></th>';
print getTitleFieldOfList('ShipmentRef', 0, $_SERVER['PHP_SELF'], 'e.ref', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('Company', 0, $_SERVER['PHP_SELF'], 's.nom', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('Date', 0, $_SERVER['PHP_SELF'], 'e.date_expedition', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('CoveredQuantity', 0, $_SERVER['PHP_SELF'], 'covered_qty', '', $listparam, 'class="right"', $sortfield, $sortorder);
print getTitleFieldOfList('WarrantyExpiryRange', 0, $_SERVER['PHP_SELF'], 'warranty_expiry_max', '', $listparam, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('WarrantyLetter').'</th>';
print '<th class="right">'.$langs->trans('ShowDetails').'</th>';
print '</tr>';

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
} else {
    if ($db->num_rows($resql) === 0) {
        print '<tr class="oddeven"><td colspan="'.($canCreateLetter ? 8 : 7).'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
    }
    while ($row = $db->fetch_object($resql)) {
        $shipmentId = (int) $row->shipment_id;
        print '<tr class="oddeven">';
        if ($canCreateLetter) {
            print '<td class="center">';
            if (empty($row->fk_letter)) {
                print '<input type="checkbox" class="warranty-shipment-select" name="shipmentids[]" value="'.$shipmentId.'" data-socid="'.((int) $row->fk_soc).'" form="warrantyShipmentCreateForm">';
            } else {
                print '<span class="opacitymedium">—</span>';
            }
            print '</td>';
        }
        print '<td><a href="'.DOL_URL_ROOT.'/expedition/card.php?id='.$shipmentId.'">'.dol_escape_htmltag($row->shipment_ref).'</a></td>';
        print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $row->fk_soc).'">'.dol_escape_htmltag($row->company_name).'</a></td>';
        print '<td>'.(!empty($row->date_expedition) ? dol_print_date($db->jdate($row->date_expedition), 'day') : '').'</td>';
        print '<td class="right">'.price((float) $row->covered_qty, 0, '', 0, 0, 2).'</td>';
        print '<td>';
        if (!empty($row->warranty_expiry_min) || !empty($row->warranty_expiry_max)) {
            $expiryMin = !empty($row->warranty_expiry_min) ? dol_print_date($db->jdate($row->warranty_expiry_min), 'day') : '';
            $expiryMax = !empty($row->warranty_expiry_max) ? dol_print_date($db->jdate($row->warranty_expiry_max), 'day') : '';
            if ($expiryMin !== '' && $expiryMax !== '' && $expiryMin !== $expiryMax) {
                print $expiryMin.' &ndash; '.$expiryMax;
            } else {
                print $expiryMax !== '' ? $expiryMax : $expiryMin;
            }
        } else {
            print '<span class="opacitymedium">&mdash;</span>';
        }
        print '</td>';
        print '<td>';
        if (!empty($row->fk_letter)) {
            print '<a href="'.dol_buildpath('/warrantysvc/warranty_letter_card.php',1).'?id='.((int) $row->fk_letter).'">'.dol_escape_htmltag($row->letter_ref).'</a>';
        } else {
            print '<span class="opacitymedium">'.$langs->trans('None').'</span>';
        }
        print '</td>';
        print '<td class="right"><a href="'.dol_buildpath('/warrantysvc/warranty_list.php',1).'?shipmentid='.$shipmentId.'">'.$langs->trans('ShowDetails').'</a></td>';
        print '</tr>';
    }
    $db->free($resql);
}
print '</table></div>';
print '</form>';

if ($canCreateLetter) {
    print '<div class="tabsAction">';
    print dolGetButtonAction(
        '',
        $langs->trans('WarrantyLetterCreateFromSelected'),
        'default',
        '',
        '',
        1,
        array('attr'=>array(
            'onclick'=>"document.getElementById('warrantyShipmentCreateForm').submit();",
            'role'=>'button',
            'tabindex'=>'0'
        ))
    );
    print '</div>';
    print '<script>
    (function(){
        const boxes=[...document.querySelectorAll(".warranty-shipment-select")];
        boxes.forEach(function(box){
            box.addEventListener("change",function(){
                const selected=boxes.filter(b=>b.checked);
                const socids=[...new Set(selected.map(b=>b.dataset.socid))];
                boxes.forEach(function(b){
                    b.disabled=socids.length===1 && !b.checked && b.dataset.socid!==socids[0];
                });
                if(selected.length===0) boxes.forEach(b=>b.disabled=false);
            });
        });
    })();
    </script>';
}

llxFooter();
$db->close();
