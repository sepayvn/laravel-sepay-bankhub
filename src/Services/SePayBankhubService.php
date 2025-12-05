<?php

declare(strict_types=1);

namespace SePay\SePayBankhub\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SePayBankhubService
{
    private const CACHE_TOKEN_KEY = 'bankhub_access_token';

    private const CACHE_TOKEN_TTL_BUFFER = 60; // Cache token với buffer 60 giây trước khi hết hạn

    /**
     * Lấy access token từ API, cache token để tái sử dụng
     */
    public function getAccessToken(): ?string
    {
        // Kiểm tra cache trước
        $cachedToken = Cache::get(self::CACHE_TOKEN_KEY);
        if ($cachedToken !== null) {
            return $cachedToken;
        }

        // Lấy token mới từ API
        $apiKey = config()->string('sepay-bankhub.api_key');
        $apiSecret = config()->string('sepay-bankhub.api_secret');

        try {
            $response = $this->httpClient()
                ->withBasicAuth($apiKey, $apiSecret)
                ->post('/token/create');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get access token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $response->throw();
            }

            /** @var array{access_token: string, ttl: int} $data */
            $data = $response->json('data');

            $accessToken = $data['access_token'];
            $ttl = (int) $data['ttl']; // TTL tính bằng giây

            // Cache token với TTL trừ đi buffer để đảm bảo token còn hiệu lực
            $cacheTtl = max(0, $ttl - self::CACHE_TOKEN_TTL_BUFFER);
            Cache::put(self::CACHE_TOKEN_KEY, $accessToken, $cacheTtl);

            return $accessToken;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting access token', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Lấy danh sách bank từ API (có cache)
     *
     * @return array<int, array{id: string, brand_name: string, full_name: string, short_name: string, code: string, bin: string, logo_path: string, icon: string, active: string}>
     */
    public function getBanks(): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get banks without access token');

            return [];
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get('/bank');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get banks', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $response->throw();
            }

            $banks = $response->json('data', []);

            return $banks;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting banks', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Xóa cached token (dùng khi cần force refresh token)
     */
    public function clearTokenCache(): void
    {
        Cache::forget(self::CACHE_TOKEN_KEY);
    }

    /**
     * Tạo công ty mới trên Sepay
     *
     * @return array<string, mixed>|null Trả về dữ liệu công ty đã tạo hoặc null nếu thất bại
     */
    public function createCompany(string $fullName, string $shortName): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create company without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/company/create', [
                    'full_name' => $fullName,
                    'short_name' => $shortName,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create company', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'full_name' => $fullName,
                    'short_name' => $shortName,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating company', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'full_name' => $fullName,
                'short_name' => $shortName,
            ]);

            return null;
        }
    }

    /**
     * Chỉnh sửa thông tin công ty trên Sepay
     *
     * @param  string  $companyId  ID của công ty cần chỉnh sửa
     * @param  string  $fullName  Tên đầy đủ công ty
     * @param  string  $shortName  Tên viết tắt công ty
     * @param  string  $status  Trạng thái công ty (Pending, Active, Suspended, Terminated, Cancelled, Fraud)
     * @return array<string, mixed>|null Trả về dữ liệu công ty đã cập nhật hoặc null nếu thất bại
     */
    public function editCompany(
        string $companyId,
        string $fullName,
        string $shortName,
        string $status
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot edit company without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/company/edit/{$companyId}", [
                    'full_name' => $fullName,
                    'short_name' => $shortName,
                    'status' => $status,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to edit company', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'full_name' => $fullName,
                    'short_name' => $shortName,
                    'status' => $status,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while editing company', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'full_name' => $fullName,
                'short_name' => $shortName,
                'status' => $status,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn bộ đếm giao dịch của Merchant
     *
     * @param  string|null  $date  Ngày lọc theo định dạng Y-m-d
     * @return array{dates: array<int, array{company_id: string, date: string, transaction: string, transaction_in: string, transaction_out: string}>, total: array{transaction: int, transaction_in: int, transaction_out: int}}|null
     */
    public function getMerchantCounter(?string $date = null): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get merchant counter without access token');

            return null;
        }

        try {
            $client = $this->httpClient()->withToken($accessToken);

            if ($date !== null) {
                $client = $client->withQueryParameters(['date' => $date]);
            }

            $response = $client->get('/merchant/v1/merchant/counter');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get merchant counter', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'date' => $date,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting merchant counter', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'date' => $date,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn danh sách công ty (tổ chức)
     *
     * @param  int|null  $perPage  Số bản ghi trên trang
     * @param  string|null  $query  Từ khóa tìm kiếm
     * @param  string|null  $status  Lọc theo trạng thái (Pending, Active, Suspended, Terminated, Cancelled, Fraud)
     * @param  string|null  $sortCreatedAt  Sắp xếp theo ngày tạo (asc, desc)
     * @return array{data: array<int, array{id: string, full_name: string, short_name: string, status: string, created_at: string, updated_at: string}>, meta: array{per_page: int, total: int, has_more: bool, current_page: int, page_count: int}}|null
     */
    public function listCompanies(
        ?int $perPage = null,
        ?string $query = null,
        ?string $status = null,
        ?string $sortCreatedAt = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot list companies without access token');

            return null;
        }

        try {
            $params = [];

            if ($perPage !== null) {
                $params['per_page'] = (string) $perPage;
            }

            if ($query !== null) {
                $params['q'] = $query;
            }

            if ($status !== null) {
                $params['status'] = $status;
            }

            if ($sortCreatedAt !== null) {
                $params['sort[created_at]'] = $sortCreatedAt;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withQueryParameters($params)
                ->get('/merchant/v1/company');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to list companies', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $params,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while listing companies', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Truy vấn chi tiết công ty (tổ chức)
     *
     * @return array{id: string, full_name: string, short_name: string, status: string, created_at: string, updated_at: string}|null
     */
    public function getCompanyDetails(string $companyId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get company details without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/company/details/{$companyId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get company details', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting company details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn cấu hình công ty (tổ chức)
     *
     * @return array{payment_code: string, payment_code_prefix: string, payment_code_suffix_from: int, payment_code_suffix_to: int, payment_code_suffix_character_type: string, transaction_amount: int|string}|null
     */
    public function getCompanyConfiguration(string $companyId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get company configuration without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/company/configuration/{$companyId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get company configuration', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting company configuration', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
            ]);

            return null;
        }
    }

    /**
     * Cập nhật cấu hình công ty (tổ chức)
     *
     * @param  array<string, mixed>  $config  Cấu hình cần cập nhật
     * @return array<string, mixed>|null
     */
    public function updateCompanyConfiguration(string $companyId, array $config): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot update company configuration without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/company/configuration/{$companyId}", $config);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to update company configuration', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'config' => $config,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while updating company configuration', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'config' => $config,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn bộ đếm công ty (tổ chức)
     *
     * @param  string|null  $date  Ngày lọc theo định dạng Y-m-d
     * @return array{dates: array<int, array{company_id: string, date: string, transaction: string, transaction_in: string, transaction_out: string}>, total: array{transaction: int, transaction_in: int, transaction_out: int}}|null
     */
    public function getCompanyCounter(string $companyId, ?string $date = null): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get company counter without access token');

            return null;
        }

        try {
            $client = $this->httpClient()->withToken($accessToken);

            if ($date !== null) {
                $client = $client->withQueryParameters(['date' => $date]);
            }

            $response = $client->get("/merchant/v1/company/counter/{$companyId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get company counter', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'date' => $date,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting company counter', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'date' => $date,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn danh sách tài khoản ngân hàng
     *
     * @param  int|null  $perPage  Số bản ghi trên trang
     * @param  string|null  $query  Từ khóa tìm kiếm
     * @param  string|null  $companyId  Lọc theo ID công ty
     * @param  string|null  $bankId  Lọc theo ID ngân hàng
     * @return array{data: array<int, array{id: string, company_id: string, bank_id: string, account_holder_name: string, account_number: string, accumulated: string, label: string, bank_api_connected: string, last_transaction: string|null, created_at: string, updated_at: string}>, meta: array{per_page: int, total: int, has_more: bool, current_page: int, page_count: int}}|null
     */
    public function listBankAccounts(
        ?int $perPage = null,
        ?string $query = null,
        ?string $companyId = null,
        ?string $bankId = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot list bank accounts without access token');

            return null;
        }

        try {
            $params = [];

            if ($perPage !== null) {
                $params['per_page'] = (string) $perPage;
            }

            if ($query !== null) {
                $params['q'] = $query;
            }

            if ($companyId !== null) {
                $params['company_id'] = $companyId;
            }

            if ($bankId !== null) {
                $params['bank_id'] = $bankId;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withQueryParameters($params)
                ->get('/merchant/v1/bankAccount');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to list bank accounts', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $params,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while listing bank accounts', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Truy vấn chi tiết tài khoản ngân hàng
     *
     * @return array{id: string, company_id: string, bank_id: string, account_holder_name: string, account_number: string, accumulated: string, label: string, bank_api_connected: string, last_transaction: string|null, created_at: string, updated_at: string}|null
     */
    public function getBankAccountDetails(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get bank account details without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/bankAccount/details/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get bank account details', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting bank account details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn lịch sử giao dịch
     *
     * @param  int|null  $perPage  Số bản ghi trên trang
     * @param  string|null  $query  Từ khóa tìm kiếm
     * @param  string|null  $companyId  Lọc theo ID công ty
     * @param  string|null  $bankId  Lọc theo ID ngân hàng
     * @param  string|null  $bankAccountId  Lọc theo ID tài khoản ngân hàng
     * @param  string|null  $transactionDate  Lọc theo ngày giao dịch (Y-m-d hoặc Y-m-d H:i:s)
     * @param  string|null  $startTransactionDate  Lọc theo ngày bắt đầu (Y-m-d hoặc Y-m-d H:i:s)
     * @param  string|null  $endTransactionDate  Lọc theo ngày kết thúc (Y-m-d hoặc Y-m-d H:i:s)
     * @param  string|null  $transferType  Lọc theo loại giao dịch (credit, debit)
     * @param  string|null  $vaId  Lọc theo ID VA
     * @return array{data: array<int, array{id: string, transaction_id: string, transaction_date: string, bank_account_id: string, account_number: string, company_id: string, bank_id: string, va_id: string|null, va: string|null, reference_number: string|null, transaction_content: string, payment_code: string|null, transfer_type: string, amount: string}>, meta: array{per_page: int, total: int, has_more: bool, current_page: int, page_count: int}}|null
     */
    public function listTransactions(
        ?int $perPage = null,
        ?string $query = null,
        ?string $companyId = null,
        ?string $bankId = null,
        ?string $bankAccountId = null,
        ?string $transactionDate = null,
        ?string $startTransactionDate = null,
        ?string $endTransactionDate = null,
        ?string $transferType = null,
        ?string $vaId = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot list transactions without access token');

            return null;
        }

        try {
            $params = [];

            if ($perPage !== null) {
                $params['per_page'] = (string) $perPage;
            }

            if ($query !== null) {
                $params['q'] = $query;
            }

            if ($companyId !== null) {
                $params['company_id'] = $companyId;
            }

            if ($bankId !== null) {
                $params['bank_id'] = $bankId;
            }

            if ($bankAccountId !== null) {
                $params['bank_account_id'] = $bankAccountId;
            }

            if ($transactionDate !== null) {
                $params['transaction_date'] = $transactionDate;
            }

            if ($startTransactionDate !== null) {
                $params['start_transaction_date'] = $startTransactionDate;
            }

            if ($endTransactionDate !== null) {
                $params['end_transaction_date'] = $endTransactionDate;
            }

            if ($transferType !== null) {
                $params['transfer_type'] = $transferType;
            }

            if ($vaId !== null) {
                $params['va_id'] = $vaId;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withQueryParameters($params)
                ->get('/merchant/v1/transaction');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to list transactions', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $params,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while listing transactions', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Truy vấn chi tiết lịch sử giao dịch
     *
     * @return array{id: string, transaction_id: string, transaction_date: string, bank_account_id: string, account_number: string, company_id: string, bank_id: string, va_id: string|null, va: string|null, reference_number: string|null, transaction_content: string, payment_code: string|null, transfer_type: string, amount: string}|null
     */
    public function getTransactionDetails(string $transactionId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get transaction details without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/transaction/details/{$transactionId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get transaction details', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'transaction_id' => $transactionId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting transaction details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'transaction_id' => $transactionId,
            ]);

            return null;
        }
    }

    /**
     * Tạo tài khoản ngân hàng OCB cho cá nhân
     *
     * @return array<string, mixed>|null Trả về dữ liệu tài khoản đã tạo hoặc null nếu thất bại
     */
    public function createOcbIndividualBankAccount(
        string $companyId,
        string $accountHolderName,
        string $accountNumber,
        string $identificationNumber,
        string $phoneNumber,
        string $label
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create OCB bank account without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/ocb/individual/bankAccount/create', [
                    'company_id' => $companyId,
                    'account_holder_name' => $accountHolderName,
                    'account_number' => $accountNumber,
                    'identification_number' => $identificationNumber,
                    'phone_number' => $phoneNumber,
                    'label' => $label,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create OCB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating OCB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Thêm tài khoản liên kết ngân hàng ACB dành cho cá nhân
     *
     * @return array<string, mixed>|null Trả về response với code 2011 (cần OTP) hoặc 2012 (đã liên kết thành công)
     */
    public function createAcbBankAccount(
        string $companyId,
        string $accountHolderName,
        string $accountNumber,
        string $phoneNumber,
        ?string $label = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create ACB bank account without access token');

            return null;
        }

        try {
            $payload = [
                'company_id' => $companyId,
                'account_holder_name' => $accountHolderName,
                'account_number' => $accountNumber,
                'phone_number' => $phoneNumber,
            ];

            if ($label !== null) {
                $payload['label'] = $label;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/acb/individual/bankAccount/create', $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create ACB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating ACB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn tên chủ tài khoản ngân hàng ACB
     *
     * @return array{account_holder_name: string}|null
     */
    public function lookupAcbAccountHolderName(string $accountNumber): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot lookup ACB account holder name without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/acb/individual/bankAccount/lookUpAccountHolderName', [
                    'account_number' => $accountNumber,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to lookup ACB account holder name', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while looking up ACB account holder name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận thêm tài khoản liên kết ngân hàng ACB với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API tạo tài khoản
     * @param  string  $otp  Mã OTP
     * @return array<string, mixed>|null
     */
    public function confirmAcbApiConnection(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm ACB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/acb/individual/bankAccount/confirmApiConnection', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm ACB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming ACB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu liên kết tài khoản ngân hàng ACB và gửi lại mã OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestAcbApiConnection(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request ACB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/acb/individual/bankAccount/requestApiConnection/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request ACB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting ACB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu xóa tài khoản liên kết ngân hàng ACB. Hệ thống sẽ gửi OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestAcbDelete(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request ACB delete without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/acb/individual/bankAccount/requestDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request ACB delete', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting ACB delete', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận xóa tài khoản liên kết ngân hàng ACB với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API yêu cầu xóa
     * @param  string  $otp  Mã OTP
     * @return array<string, mixed>|null
     */
    public function confirmAcbDelete(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm ACB delete without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/acb/individual/bankAccount/confirmDelete', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm ACB delete', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming ACB delete', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Xóa tài khoản chưa liên kết API trước đó ngân hàng ACB
     *
     * @return array<string, mixed>|null
     */
    public function forceDeleteAcbBankAccount(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot force delete ACB bank account without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/acb/individual/bankAccount/forceDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to force delete ACB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while force deleting ACB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Thêm tài khoản liên kết ngân hàng MB dành cho cá nhân
     *
     * @return array<string, mixed>|null Trả về response với code 2011 (cần OTP) hoặc 2012 (đã liên kết thành công)
     */
    public function createMbBankAccount(
        string $companyId,
        string $accountHolderName,
        string $accountNumber,
        string $identificationNumber,
        string $phoneNumber,
        ?string $label = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create MB bank account without access token');

            return null;
        }

        try {
            $payload = [
                'company_id' => $companyId,
                'account_holder_name' => $accountHolderName,
                'account_number' => $accountNumber,
                'identification_number' => $identificationNumber,
                'phone_number' => $phoneNumber,
            ];

            if ($label !== null) {
                $payload['label'] = $label;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/mb/individual/bankAccount/create', $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create MB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating MB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn tên chủ tài khoản ngân hàng MB
     *
     * @return array{account_holder_name: string}|null
     */
    public function lookupMbAccountHolderName(string $accountNumber): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot lookup MB account holder name without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/mb/individual/bankAccount/lookUpAccountHolderName', [
                    'account_number' => $accountNumber,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to lookup MB account holder name', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while looking up MB account holder name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận thêm tài khoản liên kết ngân hàng MB với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API tạo tài khoản
     * @param  string  $otp  Mã OTP 8 chữ số
     * @return array<string, mixed>|null
     */
    public function confirmMbApiConnection(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm MB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/mb/individual/bankAccount/confirmApiConnection', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm MB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming MB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu liên kết tài khoản ngân hàng MB và gửi lại mã OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestMbApiConnection(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request MB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/mb/individual/bankAccount/requestApiConnection/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request MB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting MB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu xóa tài khoản liên kết ngân hàng MB. Hệ thống sẽ gửi OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestMbDelete(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request MB delete without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/mb/individual/bankAccount/requestDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request MB delete', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting MB delete', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận xóa tài khoản liên kết ngân hàng MB với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API yêu cầu xóa
     * @param  string  $otp  Mã OTP 8 chữ số
     * @return array<string, mixed>|null
     */
    public function confirmMbDelete(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm MB delete without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/mb/individual/bankAccount/confirmDelete', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm MB delete', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming MB delete', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Xóa tài khoản chưa liên kết API trước đó ngân hàng MB
     *
     * @return array<string, mixed>|null
     */
    public function forceDeleteMbBankAccount(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot force delete MB bank account without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/mb/individual/bankAccount/forceDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to force delete MB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while force deleting MB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn tên chủ tài khoản ngân hàng OCB
     *
     * @return array{account_holder_name: string}|null
     */
    public function lookupOcbAccountHolderName(string $accountNumber): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot lookup OCB account holder name without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/ocb/individual/bankAccount/lookUpAccountHolderName', [
                    'account_number' => $accountNumber,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to lookup OCB account holder name', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while looking up OCB account holder name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu tạo VA cho tài khoản liên kết ngân hàng OCB. Hệ thống sẽ gửi OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestOcbVaCreate(
        string $bankAccountId,
        string $companyId,
        string $merchantName,
        string $email,
        string $merchantAddress,
        string $va,
        ?string $label = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request OCB VA create without access token');

            return null;
        }

        try {
            $payload = [
                'bank_account_id' => $bankAccountId,
                'company_id' => $companyId,
                'merchant_name' => $merchantName,
                'email' => $email,
                'merchant_address' => $merchantAddress,
                'va' => $va,
            ];

            if ($label !== null) {
                $payload['label'] = $label;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/ocb/individual/VA/requestCreate', $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request OCB VA create', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                    'company_id' => $companyId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting OCB VA create', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
                'company_id' => $companyId,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận tạo VA tài khoản liên kết ngân hàng OCB với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API yêu cầu tạo VA
     * @param  string  $otp  Mã OTP 6 chữ số
     * @return array{id: string}|null
     */
    public function confirmOcbVaCreate(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm OCB VA create without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/ocb/individual/VA/confirmCreate', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm OCB VA create', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming OCB VA create', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn danh sách VA thuộc tài khoản ngân hàng OCB
     *
     * @param  int|null  $perPage  Số bản ghi trên trang
     * @param  string|null  $query  Từ khóa tìm kiếm
     * @param  string|null  $companyId  Lọc theo ID công ty
     * @param  string|null  $bankAccountId  Lọc theo ID tài khoản ngân hàng
     * @return array{data: array<int, array{id: string, company_id: string, bank_account_id: string, va: string, label: string, active: string, created_at: string, updated_at: string}>, meta: array{per_page: int, total: int, has_more: bool, current_page: int, page_count: int}}|null
     */
    public function listOcbVas(
        ?int $perPage = null,
        ?string $query = null,
        ?string $companyId = null,
        ?string $bankAccountId = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot list OCB VAs without access token');

            return null;
        }

        try {
            $params = [];

            if ($perPage !== null) {
                $params['per_page'] = (string) $perPage;
            }

            if ($query !== null) {
                $params['q'] = $query;
            }

            if ($companyId !== null) {
                $params['company_id'] = $companyId;
            }

            if ($bankAccountId !== null) {
                $params['bank_account_id'] = $bankAccountId;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withQueryParameters($params)
                ->get('/merchant/v1/ocb/individual/VA');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to list OCB VAs', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $params,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while listing OCB VAs', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Truy vấn chi tiết VA thuộc tài khoản ngân hàng OCB
     *
     * @return array{id: string, company_id: string, bank_account_id: string, va: string, label: string, active: string, created_at: string, updated_at: string}|null
     */
    public function getOcbVaDetails(string $vaId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get OCB VA details without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/ocb/individual/VA/details/{$vaId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get OCB VA details', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'va_id' => $vaId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting OCB VA details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'va_id' => $vaId,
            ]);

            return null;
        }
    }

    /**
     * Cập nhật thông tin tài khoản ngân hàng OCB dành cho cá nhân
     *
     * @return array<string, mixed>|null
     */
    public function editOcbBankAccount(
        string $bankAccountId,
        ?string $identificationNumber = null,
        ?string $phoneNumber = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot edit OCB bank account without access token');

            return null;
        }

        try {
            $payload = [];

            if ($identificationNumber !== null) {
                $payload['identification_number'] = $identificationNumber;
            }

            if ($phoneNumber !== null) {
                $payload['phone_number'] = $phoneNumber;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/ocb/individual/bankAccount/edit/{$bankAccountId}", $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to edit OCB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while editing OCB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Xóa tài khoản chưa liên kết API trước đó ngân hàng OCB
     *
     * @return array<string, mixed>|null
     */
    public function forceDeleteOcbBankAccount(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot force delete OCB bank account without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/ocb/individual/bankAccount/forceDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to force delete OCB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while force deleting OCB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn tên chủ tài khoản ngân hàng KienLongBank
     *
     * @return array{account_holder_name: string}|null
     */
    public function lookupKlbAccountHolderName(string $accountNumber): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot lookup KLB account holder name without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/klb/bankAccount/lookUpAccountHolderName', [
                    'account_number' => $accountNumber,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to lookup KLB account holder name', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while looking up KLB account holder name', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Tạo VA cho tài khoản liên kết ngân hàng KienLongBank
     *
     * @param  string  $bankAccountId  ID tài khoản ngân hàng
     * @param  string  $companyId  ID công ty
     * @param  string|null  $label  Tên gợi nhớ
     * @return array{id: string}|null
     */
    public function createKlbVa(
        string $bankAccountId,
        string $companyId,
        ?string $label = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create KLB VA without access token');

            return null;
        }

        try {
            $payload = [
                'bank_account_id' => $bankAccountId,
                'company_id' => $companyId,
            ];

            if ($label !== null) {
                $payload['label'] = $label;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/klb/VA/create', $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create KLB VA', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                    'company_id' => $companyId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating KLB VA', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
                'company_id' => $companyId,
            ]);

            return null;
        }
    }

    /**
     * Thêm tài khoản liên kết ngân hàng KienLongBank dành cho cá nhân/doanh nghiệp
     *
     * @return array<string, mixed>|null Trả về response với code 2011 (cần OTP) hoặc 2012 (đã liên kết thành công)
     */
    public function createKlbBankAccount(
        string $companyId,
        string $accountNumber,
        ?string $label = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot create KLB bank account without access token');

            return null;
        }

        try {
            $payload = [
                'company_id' => $companyId,
                'account_number' => $accountNumber,
            ];

            if ($label !== null) {
                $payload['label'] = $label;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post('/merchant/v1/klb/bankAccount/create', $payload);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to create KLB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'company_id' => $companyId,
                    'account_number' => $accountNumber,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while creating KLB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'company_id' => $companyId,
                'account_number' => $accountNumber,
            ]);

            return null;
        }
    }

    /**
     * Xác nhận thêm tài khoản liên kết ngân hàng KienLongBank với OTP
     *
     * @param  string  $requestId  REQUEST_ID từ API tạo tài khoản
     * @param  string  $otp  Mã OTP 6 chữ số
     * @return array<string, mixed>|null
     */
    public function confirmKlbApiConnection(string $requestId, string $otp): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot confirm KLB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withHeader('Request-Id', $requestId)
                ->post('/merchant/v1/klb/bankAccount/confirmApiConnection', [
                    'otp' => $otp,
                ]);

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to confirm KLB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'request_id' => $requestId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while confirming KLB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
            ]);

            return null;
        }
    }

    /**
     * Yêu cầu liên kết tài khoản ngân hàng KienLongBank và gửi lại mã OTP
     *
     * @return array{request_id: string}|null
     */
    public function requestKlbApiConnection(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot request KLB API connection without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/klb/bankAccount/requestApiConnection/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to request KLB API connection', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while requesting KLB API connection', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Xóa tài khoản chưa liên kết API trước đó ngân hàng KienLongBank
     *
     * @return array<string, mixed>|null
     */
    public function forceDeleteKlbBankAccount(string $bankAccountId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot force delete KLB bank account without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/klb/bankAccount/forceDelete/{$bankAccountId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to force delete KLB bank account', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'bank_account_id' => $bankAccountId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while force deleting KLB bank account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'bank_account_id' => $bankAccountId,
            ]);

            return null;
        }
    }

    /**
     * Kích hoạt lại VA cho tài khoản liên kết ngân hàng KienLongBank
     *
     * @return array<string, mixed>|null
     */
    public function enableKlbVa(string $vaId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot enable KLB VA without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/klb/VA/enable/{$vaId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to enable KLB VA', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'va_id' => $vaId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while enabling KLB VA', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'va_id' => $vaId,
            ]);

            return null;
        }
    }

    /**
     * Vô hiệu hóa VA cho tài khoản liên kết ngân hàng KienLongBank
     *
     * @return array<string, mixed>|null
     */
    public function disableKlbVa(string $vaId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot disable KLB VA without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->post("/merchant/v1/klb/VA/disable/{$vaId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to disable KLB VA', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'va_id' => $vaId,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while disabling KLB VA', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'va_id' => $vaId,
            ]);

            return null;
        }
    }

    /**
     * Truy vấn danh sách VA thuộc tài khoản ngân hàng KienLongBank
     *
     * @param  int|null  $perPage  Số bản ghi trên trang
     * @param  string|null  $query  Từ khóa tìm kiếm
     * @param  string|null  $companyId  Lọc theo ID công ty
     * @param  string|null  $bankAccountId  Lọc theo ID tài khoản ngân hàng
     * @return array{data: array<int, array{id: string, company_id: string, bank_account_id: string, va: string, label: string, active: string, created_at: string, updated_at: string}>, meta: array{per_page: int, total: int, has_more: bool, current_page: int, page_count: int}}|null
     */
    public function listKlbVas(
        ?int $perPage = null,
        ?string $query = null,
        ?string $companyId = null,
        ?string $bankAccountId = null
    ): ?array {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot list KLB VAs without access token');

            return null;
        }

        try {
            $params = [];

            if ($perPage !== null) {
                $params['per_page'] = (string) $perPage;
            }

            if ($query !== null) {
                $params['q'] = $query;
            }

            if ($companyId !== null) {
                $params['company_id'] = $companyId;
            }

            if ($bankAccountId !== null) {
                $params['bank_account_id'] = $bankAccountId;
            }

            $response = $this->httpClient()
                ->withToken($accessToken)
                ->withQueryParameters($params)
                ->get('/merchant/v1/klb/VA');

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to list KLB VAs', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $params,
                ]);

                $response->throw();
            }

            $result = $response->json();

            return $result;
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while listing KLB VAs', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Truy vấn chi tiết VA thuộc tài khoản ngân hàng KienLongBank
     *
     * @return array{id: string, company_id: string, bank_account_id: string, va: string, label: string, active: string, created_at: string, updated_at: string}|null
     */
    public function getKlbVaDetails(string $vaId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('SePayBankhubService: Cannot get KLB VA details without access token');

            return null;
        }

        try {
            $response = $this->httpClient()
                ->withToken($accessToken)
                ->get("/merchant/v1/klb/VA/details/{$vaId}");

            if (! $response->successful()) {
                Log::error('SePayBankhubService: Failed to get KLB VA details', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'va_id' => $vaId,
                ]);

                $response->throw();
            }

            return $response->json('data');
        } catch (Exception $e) {
            Log::error('SePayBankhubService: Exception while getting KLB VA details', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'va_id' => $vaId,
            ]);

            return null;
        }
    }

    /**
     * Lấy base URL từ config
     */
    private function getBaseUrl(): string
    {
        return config()->string('sepay-bankhub.api_url');
    }

    /**
     * Tạo HTTP client với base URL đã được config
     */
    private function httpClient(): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->withHeader('Client-Message-Id', Str::uuid()->toString());
    }
}
