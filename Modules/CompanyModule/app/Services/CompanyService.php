<?php

namespace Modules\CompanyModule\app\Services;

use Modules\CompanyModule\app\Repositories\CompanyRepository;

/**
 * Every method takes the owner account id, so an account can only reach its own companies.
 */
class CompanyService
{
    private $companyRepository;

    public function __construct(CompanyRepository $companyRepository)
    {
        $this->companyRepository = $companyRepository;
    }

    // list query (paged / ordered by DataTables)
    public function listQuery($accountId, array $filters = [])
    {
        return $this->companyRepository->forAccount($accountId)->filter($filters);
    }

    public function countForAccount($accountId): int
    {
        return $this->companyRepository->forAccount($accountId)->count();
    }

    // [id => name] of the account companys, for select inputs
    public function options($accountId): array
    {
        return $this->companyRepository->forAccount($accountId)->orderByLocalized('name')->get()->pluck('name', 'id')->all();
    }

    // 404 when the company belongs to another account
    public function findOne($accountId, $id)
    {
        return $this->companyRepository->forAccount($accountId)->findOrFail($id);
    }

    public function create($accountId, array $data)
    {
        return $this->companyRepository->create($this->companyData($data) + ['account_id' => $accountId]);
    }

    public function update($accountId, $id, array $data)
    {
        $company = $this->findOne($accountId, $id);
        $company->update($this->companyData($data));
        return $company;
    }

    // soft delete
    public function deleteOne($accountId, $id)
    {
        return $this->findOne($accountId, $id)->delete();
    }

    private function companyData(array $data): array
    {
        return [
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'commercial_register' => ($data['commercial_register'] ?? null) ?: null,
            'tax_number' => ($data['tax_number'] ?? null) ?: null,
            'address' => ($data['address'] ?? null) ?: null,
            'representative_name' => ($data['representative_name'] ?? null) ?: null,
            'representative_title' => ($data['representative_title'] ?? null) ?: null,
            'representative_national_id' => ($data['representative_national_id'] ?? null) ?: null,
        ];
    }
}
