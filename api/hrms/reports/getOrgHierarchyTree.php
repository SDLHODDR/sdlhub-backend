<?php
/* GET ?org_loc_id= -> the organogram tree under a location, for Organogram Hierarchy - Visual
 * (legacy organogram/index.php). Node: id, name (location), title (employee or Vacant), dept, desi, children. */
require_once __DIR__ . '/reportsCommon.php';

reportsRunOptions('orglochierarchyvisual', static function () {
    [$locId, $parent] = reportsHierarchyRoot();
    $rows = multiRec("SELECT HOL.ID, HOL.PARENT_LOCID, NVL(GET_ORGLOC_EMP(HOL.ID), 'Vacant') AS TITLE, GET_ORG_LOC_NAME(HOL.ID) AS NAME,
            GET_DEPT_NAME(HO.DEPT_ID) AS DEPT, GET_DESIGN_NAME(HO.DESI_ID) AS DESI
        FROM HR_ORGANOGRAM_LOC HOL
        INNER JOIN HR_ORGANOGRAM HO ON HO.ID = HOL.ORG_ID
        START WITH HOL.ID = :id
        CONNECT BY NOCYCLE PRIOR HOL.ID = HOL.PARENT_LOCID
        ORDER SIBLINGS BY HOL.LOC_LABEL", [':id' => $locId]) ?: [];

    $children = [];
    $nodes = [];
    foreach ($rows as $row) {
        $nodes[$row['ID']] = $row;
        $children[(string)$row['PARENT_LOCID']][] = $row['ID'];
    }
    $build = static function ($id) use (&$build, $nodes, $children) {
        $row = $nodes[$id];
        return [
            'id' => (string)$row['ID'],
            'name' => (string)$row['NAME'],
            'title' => (string)$row['TITLE'],
            'dept' => (string)($row['DEPT'] ?? ''),
            'desi' => (string)($row['DESI'] ?? ''),
            'vacant' => $row['TITLE'] === 'Vacant',
            'children' => array_map($build, $children[(string)$id] ?? []),
        ];
    };
    return ['parent' => $parent, 'count' => count($rows), 'tree' => isset($nodes[$locId]) ? $build($locId) : null];
});
