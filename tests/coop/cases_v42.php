<?php
declare(strict_types=1);

/**
 * Version 42 cases: the Inventory Center history in date order.
 */

test('Inventory Center history: a back-dated movement takes its place by date, and the balances after each movement follow the dates', function () {
    $j = $GLOBALS['juana'];
    $item = pt_item('V42BD', 'Back-dated test item', 'inventory', '150', '100', false);
    pt_bill($j['vendor'], '2026-03-10', [['item_id' => $item, 'qty' => 10, 'rate' => 100]]);
    pt_sale('invoice', $j['customer'], '2026-03-20', [['item_id' => $item, 'qty' => 4, 'rate' => 150]]);
    pt_bill($j['vendor'], '2026-03-05', [['item_id' => $item, 'qty' => 6, 'rate' => 80]]);       // recorded last, dated first
    $moves = DB::all('SELECT id, txn_date, qty, qty_after FROM inventory_movements WHERE item_id = :i ORDER BY txn_date, id', ['i' => $item]);
    eq(array_map(fn($m) => [$m['txn_date'], (float)$m['qty'], (float)$m['qty_after']], $moves),
        [['2026-03-05', 6.0, 12.0], ['2026-03-10', 10.0, 10.0], ['2026-03-20', -4.0, 6.0]], 'the stored figures are those of the moment each was recorded (wrong in date order)');
    $after = Stock::balancesAfter($item);
    eq(array_map(fn($m) => $after[(int)$m['id']]['qty'], $moves), [6.0, 16.0, 12.0], 'on hand after each movement in date order: 6, 16, 12');
    eq(end($after)['qty'], Stock::onHand($item), 'the last balance is the quantity on hand');
    eq(array_map(fn($m) => $after[(int)$m['id']]['avg'], $moves), [80.0, 92.5, 90.0], 'the average after each: 480/6, 1,480/16, 1,080/12');
    $site = pt_site();
    eq(array_map(fn($m) => Stock::balancesAfter($item, $site)[(int)$m['id']]['qty'], $moves), [6.0, 16.0, 12.0], 'the same at the site');
});
