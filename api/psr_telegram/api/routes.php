<?php

return [
    'POST' => [
        '/api/telegram/groups/create' => ['GroupController', 'create'],
        '/api/telegram/groups/list' => ['GroupController', 'list'],
        '/api/telegram/groups/list-region' => ['GroupController', 'listRegion'], 
        '/api/telegram/groups/divisions' => ['GroupController', 'listDivisions'],
        '/api/telegram/groups/companies' => ['GroupController', 'listCompanies'],
        '/api/telegram/groups/departments' => ['GroupController', 'listDepartments'],
        '/api/telegram/groups/hq' => ['GroupController', 'gettDivisionHQs'], 
         '/api/telegram/groups/save-broadcast-message' => ['GroupController', 'saveBroadcastMessage'],     
        '/api/telegram/members/create' => ['MemberController', 'create'],
        '/api/telegram/members/list-region' => ['MemberController', 'listRegion'],
        '/api/telegram/broadcast/create' => ['BroadcastController', 'create'],
        '/api/telegram/invite/generate' => ['InviteController', 'generate'],
        '/api/telegram/webhook' => ['WebhookController', 'handle'],
        '/api/telegram/members/list-members' => ['MemberController', 'getMembers'],
        '/api/telegram/groups/list-hrms-members' => ['GroupController', 'getHRMSMembers'],
        '/api/telegram/members/save-list' => ['MemberController', 'saveMembers'],
        '/api/telegram/members/listmember-group' => ['MemberController', 'fetchMemberGroups'],
        '/api/telegram/members/savemembers-group' => ['MemberController', 'saveMembersGroups'],
        '/api/telegram/members/mapuser-group' => ['MemberController', 'mapMembersGroups'],
        '/api/telegram/members/group-joined' => ['MemberController', 'groupJoined'],
        '/api/telegram/members/group-members' => ['MemberController', 'getGroupMembers'],
        '/api/telegram/members/save-member-dm' => ['MemberController', 'saveMemberDM'],
    ],
    'GET' => [
        '/api/telegram/groups/list' => ['GroupController', 'list'],
        '/api/telegram/members/list' => ['MemberController', 'listGroupMembers']
    ]
];