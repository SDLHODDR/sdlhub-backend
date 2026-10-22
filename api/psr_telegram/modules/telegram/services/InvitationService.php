<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../repositories/GroupRepository.php';
require_once __DIR__ . '/../repositories/MemberRepository.php';
require_once __DIR__ . '/../repositories/HrmsSDLRepository.php';
require_once __DIR__ . '/TelegramNodeService.php';

class InvitationService
{
    private $groups;
    private $members;
    private $hrms;
    private $telegram;

    public function __construct()
    {
        $this->groups = new GroupRepository();
        $this->members = new MemberRepository();
        $this->hrms = new HrmsSDLRepository();
        $this->telegram = new TelegramNodeService();
    }

    public function generate($payload)
    {
        $requestedGroupId = filter_var($payload['group_id'] ?? null, FILTER_VALIDATE_INT);
        $employees = $payload['employees'] ?? null;
        $expiryDays = filter_var($payload['expiry_days'] ?? 30, FILTER_VALIDATE_INT);
        $usageLimit = filter_var($payload['usage_limit'] ?? 1, FILTER_VALIDATE_INT);
        if (!$requestedGroupId || !is_array($employees) || count($employees) === 0 || count($employees) > 100) {
            throw new InvalidArgumentException('A Telegram group ID and between 1 and 100 employees are required.');
        }
        if ($expiryDays === false || $expiryDays < 1 || $expiryDays > 365 || $usageLimit !== 1) {
            throw new InvalidArgumentException('Expiry must be between 1 and 365 days and each link must be single-use.');
        }

        $group = $this->groups->getByTelegramGroupId($requestedGroupId)
            ?? $this->groups->getById($requestedGroupId);
        if (!$group || (isset($group['ACTIVE_FLAG']) && (string)$group['ACTIVE_FLAG'] !== '1')) {
            throw new InvalidArgumentException('The selected Telegram group is not available.');
        }
        $groupId = $group['ID'];

        $results = [];
        $seenEmployees = [];
        foreach ($employees as $requestedEmployee) {
            $employeeCode = trim((string)($requestedEmployee['employee_code'] ?? ''));
            if ($employeeCode === '' || isset($seenEmployees[$employeeCode])) {
                continue;
            }
            $seenEmployees[$employeeCode] = true;

            try {
                $hrmsEmployee = $this->hrms->getHRMSMemberByCode($employeeCode);
                if (!$hrmsEmployee) {
                    throw new InvalidArgumentException('Employee was not found or is inactive.');
                }
                $employee = [
                    'employee_code' => $employeeCode,
                    'first_name' => $hrmsEmployee['FNAME'] ?? '',
                    'last_name' => $hrmsEmployee['LNAME'] ?? '',
                    'mobile_number' => $hrmsEmployee['CELL'] ?? null,
                    'department_id' => $hrmsEmployee['DEPT_ID'] ?? null,
                    'division_id' => $hrmsEmployee['DIVSN_ID'] ?? null,
                ];
                $existing = $this->members->getInvitationForEmployeeGroup($employeeCode, $groupId);
                if (($existing['GROUP_STATUS'] ?? '') === 'JOINED') {
                    $results[] = ['employee_code' => $employeeCode, 'status' => 'JOINED'];
                    continue;
                }
                if (($existing['GROUP_STATUS'] ?? '') === 'PENDING' && (string)($existing['IS_ACTIVE'] ?? '0') === '1') {
                    $results[] = [
                        'employee_code' => $employeeCode,
                        'status' => 'ACTIVE',
                        'invite_link' => $existing['INVITE_LINK'],
                        'expires_at' => $existing['EXPIRES_AT'],
                    ];
                    continue;
                }

                $telegramResponse = $this->telegram->generateEmployeeInvite([
                    'telegram_group_id' => $group['TELEGRAM_GROUP_ID'],
                    'employee_code' => $employeeCode,
                    'expiry_days' => $expiryDays,
                    'usage_limit' => $usageLimit,
                ]);
                if (empty($telegramResponse['success']) || empty($telegramResponse['data']['invite_link'])) {
                    throw new Exception($telegramResponse['message'] ?? 'MTProto invitation generation failed.');
                }

                $saved = $this->members->saveEmployeeInvitation(
                    $employee,
                    $groupId,
                    $telegramResponse['data']
                );
                $results[] = [
                    'employee_code' => $employeeCode,
                    'status' => $saved['status'],
                    'invite_link' => $saved['invite_link'],
                    'expires_at' => $saved['expires_at'],
                ];
            } catch (Throwable $error) {
                $results[] = [
                    'employee_code' => $employeeCode,
                    'status' => 'FAILED',
                    'message' => $error->getMessage(),
                ];
            }
        }

        return ['results' => $results];
    }

    public function mine($employeeCode)
    {
        if ($employeeCode === '') {
            throw new InvalidArgumentException('Employee session is unavailable.');
        }
        return $this->members->listInvitationsForEmployee($employeeCode);
    }
}