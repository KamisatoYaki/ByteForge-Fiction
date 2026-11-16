<?php

namespace App\Actions\PurchaseRequests;

use App\Enums\PurchaseRequestStatus;
use App\Helpers\PurchaseRequestMoney;
use App\Models\Company;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreatePurchaseRequestAction
{
    /**
     * @param  array{title: string, purpose?: ?string, department_id?: ?int, notes?: ?string, company_id?: mixed, items: list<array<string, mixed>>}  $data
     *
     * @throws ValidationException
     */
    public static function run(User $actor, array $data): PurchaseRequest
    {
        $companyId = self::resolveCompanyId($data['company_id'] ?? null);

        if (self::requiresCompanySelection($actor) && $companyId === null) {
            throw ValidationException::withMessages([
                'company_id' => [trans('validation.fmcs_company', ['attribute' => trans('general.company')])],
            ]);
        }

        $departmentId = self::normalizeDepartmentId($data['department_id'] ?? null);
        self::assertDepartmentBelongsToCompany($departmentId, $companyId);

        $items = self::normalizeItems($data['items'] ?? []);

        return DB::transaction(function () use ($actor, $data, $companyId, $departmentId, $items) {
            $request = new PurchaseRequest;
            $request->title = $data['title'] ?? '';
            $request->purpose = $data['purpose'] ?? null;
            $request->department_id = $departmentId;
            $request->notes = $data['notes'] ?? null;
            // Currency is always taken from application settings — never from the client.
            $request->currency = Setting::getSettings()?->default_currency;
            $request->status = PurchaseRequestStatus::Draft;
            $request->requested_by = $actor->id;
            $request->created_by = $actor->id;
            $request->company_id = $companyId;
            $request->request_number = GeneratePurchaseRequestNumberAction::run();
            $request->estimated_total = PurchaseRequestMoney::normalize(0);

            if (! $request->save()) {
                throw ValidationException::withMessages(
                    $request->getErrors()->toArray()
                );
            }

            self::syncItems($request, $items);

            return $request->fresh(['items', 'requester', 'company', 'department']);
        });
    }

    public static function requiresCompanySelection(User $actor): bool
    {
        $settings = Setting::getSettings();
        if (! $settings->full_multiple_companies_support) {
            return false;
        }
        if ((bool) $settings->null_company_is_floater) {
            return false;
        }
        if ($actor->isSuperUser()) {
            return false;
        }

        return $actor->companies()->exists();
    }

    private static function resolveCompanyId(mixed $input): ?int
    {
        $resolved = Company::getIdForCurrentUser($input);

        return $resolved !== null && $resolved !== '' ? (int) $resolved : null;
    }

    public static function normalizeDepartmentId(mixed $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }

        return (int) $input;
    }

    /**
     * Ensure an optional department exists and matches the Purchase Request company.
     *
     * @throws ValidationException
     */
    public static function assertDepartmentBelongsToCompany(?int $departmentId, ?int $companyId): void
    {
        if ($departmentId === null) {
            return;
        }

        $department = Department::query()->find($departmentId);

        if (! $department) {
            throw ValidationException::withMessages([
                'department_id' => [trans('validation.exists', ['attribute' => trans('general.department')])],
            ]);
        }

        // When the PR has a company, the department must belong to that same company.
        if ($companyId !== null && (int) $department->company_id !== (int) $companyId) {
            throw ValidationException::withMessages([
                'department_id' => [trans('validation.in', ['attribute' => trans('general.department')])],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{description: string, quantity: int, estimated_unit_cost: string, category_id: ?int, specifications: ?string, sort_order: int}>
     */
    public static function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => [trans('admin/purchase_requests/message.items_required')],
            ]);
        }

        $normalized = [];
        foreach (array_values($items) as $index => $item) {
            $validator = Validator::make($item, [
                'description' => 'required|string|max:255',
                'quantity' => 'required|integer|min:1',
                'estimated_unit_cost' => 'required|numeric|gte:0|max:99999999999999999.99',
                'category_id' => 'nullable|integer|exists:categories,id',
                'specifications' => 'nullable|string|max:65535',
            ]);

            if ($validator->fails()) {
                throw ValidationException::withMessages(
                    collect($validator->errors()->toArray())
                        ->mapWithKeys(fn ($messages, $key) => ["items.$index.$key" => $messages])
                        ->all()
                );
            }

            $normalized[] = [
                'description' => (string) $item['description'],
                'quantity' => (int) $item['quantity'],
                'estimated_unit_cost' => PurchaseRequestMoney::normalize($item['estimated_unit_cost']),
                'category_id' => isset($item['category_id']) && $item['category_id'] !== ''
                    ? (int) $item['category_id']
                    : null,
                'specifications' => $item['specifications'] ?? null,
                'sort_order' => $index,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{description: string, quantity: int, estimated_unit_cost: string, category_id: ?int, specifications: ?string, sort_order: int}>  $items
     */
    public static function syncItems(PurchaseRequest $request, array $items): void
    {
        $request->items()->forceDelete();

        $totals = [];
        foreach ($items as $itemData) {
            $line = new PurchaseRequestItem;
            $line->purchase_request_id = $request->id;
            $line->description = $itemData['description'];
            $line->quantity = $itemData['quantity'];
            $line->estimated_unit_cost = $itemData['estimated_unit_cost'];
            $line->category_id = $itemData['category_id'];
            $line->specifications = $itemData['specifications'];
            $line->sort_order = $itemData['sort_order'];
            $line->recalculateLineTotal();

            if (! $line->save()) {
                throw ValidationException::withMessages(
                    $line->getErrors()->toArray()
                );
            }

            $totals[] = $line->estimated_line_total;
        }

        $request->estimated_total = PurchaseRequestMoney::sum($totals);
        $request->save();
    }
}
