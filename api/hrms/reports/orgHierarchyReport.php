<?php
/* GET ?org_loc_id= -> Organogram Hierarchy PDF. Legacy reports/admin.php orglochierarchy():
 * every path from the chosen location down, one level per column (level - location - employee or Vacant). */
require_once __DIR__ . '/reportsCommon.php';

reportsRunPdf('orglochierarchy', static function () {
    [$locId, $parent] = reportsHierarchyRoot();
    $rows = multiRec("SELECT LEVEL AS LVL,
            SYS_CONNECT_BY_PATH(REPLACE(GET_ORG_LOC_LEVEL(ID) || ' - ' || GET_ORG_LOC_NAME(ID) || ' - ' || NVL(GET_ORGLOC_EMP(ID), 'Vacant'), '|', '/'), '|') AS PATH
        FROM HR_ORGANOGRAM_LOC
        START WITH ID = :id
        CONNECT BY NOCYCLE PRIOR ID = PARENT_LOCID
        ORDER SIBLINGS BY LOC_LABEL", [':id' => $locId]) ?: [];

    $depth = max(1, ...array_map(static fn($row) => (int)$row['LVL'], $rows ?: [['LVL' => 1]]));
    $width = intdiv(100 - 3, $depth);
    $columns = [['#', 100 - $width * $depth, 'center']];
    for ($level = 1; $level <= $depth; $level++) {
        $columns[] = ["Level {$level}", $width, 'left'];
    }
    $data = [];
    foreach ($rows as $index => $row) {
        $cells = array_slice(explode('|', (string)$row['PATH']), 1);
        $data[] = array_merge([$index + 1], array_pad($cells, $depth, ''));
    }
    return ['Organogram Children Hierarchy', ['Parent' => $parent], $columns, $data, 'Organogram_Hierarchy_' . $locId];
});
