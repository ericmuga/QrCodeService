<?php

namespace App\Http\Controllers;

use App\Models\Helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Promise;
use Illuminate\Support\Facades\Http;

class ApiServiceController extends Controller
{
    public function getPortalOrdersApi()
    {
        $url = config('app.portal_orders_url'); // Replace with the actual API endpoint

        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        // Execute cURL request
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            // Handle error
            $error = curl_error($ch);
            // Handle or log the error appropriately
        }

        // Close cURL session
        curl_close($ch);

        $action = false;

        // Process the API response
        if (!empty($response)) {
            $data = json_decode($response, true);
            // Process and use $data as needed
            $action = $this->insertPortalOrders($data);
        }

        // Return the response
        $res = ['success' => $action, 'action' => 'Orders Insert', 'timestamp' => now()->addHours(3)];
        return response()->json($res);
    }

    public function insertPortalOrders($data)
    {
        $shops_customer_codes = ['96279', '99850', '95263', '94600', '97096', '93175', '99073', '96824', '98419'];

        try {
            // try insert
            foreach ($data as $d) {

                if (in_array($d['customer_code'], $shops_customer_codes)) {
                    # insert for shops...
                    $existingRecord = DB::connection('bc240')->table('FCL1$Imported Orders$23dc970e-11e8-4d9b-8613-b7582aec86ba')
                        ->where('External Document No_', $d['tracking_no'])
                        ->where('Line No_', $d['id'])
                        ->first();

                    if (!$existingRecord) {
                        DB::connection('bc240')->table('FCL1$Imported Orders$23dc970e-11e8-4d9b-8613-b7582aec86ba')->insert([
                            'External Document No_' => $d['tracking_no'],
                            'Line No_' => $d['id'],
                            'Sell-to Customer No_' => $d['customer_code'],
                            'Shipment Date' => $d['shipment_date'],
                            'Salesperson Code' => $d['sales_code'],
                            'Ship-to Code' => $d['ship_to_code'],
                            'Ship-to Name' => $d['ship_to_name'],
                            'Item No_' => $d['item_code'],
                            'Quantity' => $d['quantity'],
                            'Unit of Measure' => $d['unit_of_measure'],
                            'Status' => 0,
                            'Customer Specification' => $d['product_specifications']
                        ]);
                    }
                } else {
                    # insert into sales
                    // $existingRecord = DB::connection('sales')->table('FCL$Imported Orders')
                    //     ->where('External Document No_', $d['tracking_no'])
                    //     ->where('Line No_', $d['id'])
                    //     ->first();

                    // if (!$existingRecord) {
                    //     DB::connection('sales')->table('FCL$Imported Orders')->insert([
                    //         'External Document No_' => $d['tracking_no'],
                    //         'Line No_' => $d['id'],
                    //         'Sell-to Customer No_' => $d['customer_code'],
                    //         'Shipment Date' => $d['shipment_date'],
                    //         'Salesperson Code' => $d['sales_code'],
                    //         'Ship-to Code' => $d['ship_to_code'],
                    //         'Ship-to Name' => $d['ship_to_name'],
                    //         'Item No_' => $d['item_code'],
                    //         'Quantity' => $d['quantity'],
                    //         'Unit of Measure' => $d['unit_of_measure'],
                    //         'Status' => 0,
                    //         'Customer Specification-H' => $d['customer_specification'],
                    //         'Customer Specification-L' => $d['product_specifications'],
                    //     ]);
                    // }
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Exception in ' . __METHOD__ . '(): ' . $e->getMessage());
            return false;
        }
    }

    public function getVendorList($from = null, $to = null)
    {
        $results = DB::connection('bc240')->table('FCL1$Vendor$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->join('FCL1$SlaughterData$23dc970e-11e8-4d9b-8613-b7582aec86ba as b', 'a.No_', '=', 'b.VendorNo')
            ->select(
                'a.No_',
                'a.Phone No_ as Phone',
                'a.Contact',
                'a.Name',
                'a.City',
                'b.Settlement Date AS SettlementDate',
            )
            ->where('a.Vendor Posting Group', 'PIGFARMERS')
            ->where('a.Blocked', 0)
            ->when($from && $to, function ($query) use ($from, $to) {
                return $query->whereDate('b.Settlement Date', '>=', $from)
                    ->whereDate('b.Settlement Date', '<=', $to);
            }, function ($query) {
                return $query->whereDate('b.Settlement Date', '>=', now()->subMonth());
            })
            ->orderBy('b.Settlement Date', 'asc')
            ->distinct('a.No_')
            ->get();

        $insert_res = [true, 'No items'];

        if (!empty($results)) {
            $insert_res = $this->saveVendorsListApi($results);
        }

        $decoded_res = json_decode($insert_res);

        // Return the response
        $res = ['success' => $decoded_res[0], 'Description' => $decoded_res[1], 'action' => 'Vendors List Insert', 'timestamp' => now()->addHours(3)];

        return response()->json($res);
    }

    public function saveVendorsListApi($post_data)
    {
        $url = config('app.save_vendors_list_api');

        $helpers = new Helpers();

        $res = $helpers->send_curl($url, $post_data);

        return $res;
    }

    public function ordersStatusMain()
    {
        $salesHeader = DB::connection('bc240')->table('FCL1$Sales Header$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->whereIn('a.Document Type', ['2', '1'])
            ->whereDate('a.Posting Date', '>=', today())
            ->whereRaw("CHARINDEX(('-' + a.[Salesperson Code] + '-'), a.[External Document No_]) <> 0")
            ->select(
                'a.External Document No_ AS external_doc_no',
                DB::raw('
                            CASE
                                WHEN (a.[Status] = 4) AND (a.[Document Type] = 1) THEN 3 -- execute
                                WHEN (a.[Document Type] = 2) OR (a.[Status] = 1) THEN 4 -- post
                                WHEN (a.[Status] = 0) THEN 2 -- make order
                                ELSE NULL -- You can replace NULL with a default value if needed
                            END as [Status]')
            )
            ->get();

        $imported = DB::connection('bc240')->table('FCL1$Imported Orders$23dc970e-11e8-4d9b-8613-b7582aec86ba')
            ->whereDate('Shipment Date', '>=', today())
            ->whereNotIn('External Document No_', $salesHeader->pluck('external_doc_no'))
            ->select(
                'External Document No_ AS external_doc_no',
                DB::raw('2 As Status')
            )
            ->get();

        // Merge the result sets
        $mergedResults = $salesHeader->concat($imported);

        $action = '';

        if (!empty($mergedResults)) {
            $action = $this->updateStatusApi(json_encode($mergedResults));
        }

        return $action;
    }

    public function ordersStatusSales()
    {
        $salesHeader = DB::connection('sales')->table('FCL$Sales Header as a')
            ->whereIn('a.Document Type', ['2', '1'])
            ->whereDate('a.Posting Date', '>=', today())
            ->whereRaw("CHARINDEX(('-' + a.[Salesperson Code] + '-'), a.[External Document No_]) <> 0")
            ->select(
                'a.External Document No_ AS external_doc_no',
                DB::raw('
                            CASE
                                WHEN (a.[Status] = 4) AND (a.[Document Type] = 1) THEN 3 -- execute
                                WHEN (a.[Document Type] = 2) OR (a.[Status] = 1) THEN 4 -- post
                                WHEN (a.[Status] = 0) THEN 2 -- make order
                                ELSE NULL -- You can replace NULL with a default value if needed
                            END as [Status]')
            )
            ->get();

        $salesInvoiceHeader = DB::connection('sales')->table('FCL$Sales Invoice Header as b')
            ->whereDate('b.Posting Date', '>=', today())
            ->whereRaw("CHARINDEX(('-' + b.[Salesperson Code] + '-'), b.[External Document No_]) <> 0")
            ->select(
                'External Document No_ AS external_doc_no',
                DB::raw('4 As Status')
            )
            ->get();

        $imported = DB::connection('bc240')->table('FCL1$Imported Orders$23dc970e-11e8-4d9b-8613-b7582aec86ba')
            ->whereDate('Shipment Date', '>=', today())
            ->whereNotIn('External Document No_', $salesHeader->pluck('external_doc_no'))
            ->whereNotIn('External Document No_', $salesInvoiceHeader->pluck('external_doc_no'))
            ->select(
                'External Document No_ AS external_doc_no',
                DB::raw('2 As Status')
            )
            ->get();

        // Merge the result sets
        $mergedResults = $salesHeader
            ->concat($salesHeader)
            ->concat($salesInvoiceHeader)
            ->concat($imported);

        // dd($mergedResults);

        if (!empty($mergedResults)) {
            $action = $this->updateStatusApi(json_encode($mergedResults));
        }

        return $action;
    }

    public function updateStatusApi($post_data)
    {
        $url = config('app.update_orders_status_url');

        $helpers = new Helpers();

        $res = $helpers->send_curl($url, $post_data);

        return $res;
    }

    public function getEmployeeList()
    {
        $emps = DB::connection('bc240')->table('FCL1$Employee$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->select(
                'a.No_'
            )
            ->get();

        return response()->json($emps);
    }

    public function fetchSaveShipments()
    {
        $headers = DB::connection('bc240')->table('FCL1$Sales Invoice Header$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            // ->join('FCL$Sales Invoice Header$78dbdf4c-61b4-455a-a560-97eaca9a08b7 as b', 'a.No_', '=', 'b.No_')
            ->where('b.ShipmentNo', '!=', '')
            ->whereDate('a.Shipment Date', '>=', today()->subDays(1))
            ->select(
                'a.No_ as shipment_no',
                'a.No_ as invoice_no',
                'a.Salesperson Code as sales_code',
                'a.Sell-to Customer No_ as customer_no',
                'a.Sell-to Customer Name as customer_name',
                'a.Ship-to Code as ship_to_code',
                'a.Ship-to Name as ship_to_name',
                'a.Shipment Date as shipment_date',
                'a.Quote No_ as quote_no',
                'a.Posting Date as posting_date'
            )
            ->orderBy('a.Posting Date', 'asc')
            ->get();

        if (empty($headers)) {
            return response()->json(['success' => true, 'message' => 'No Shipment data to insert.', 'timestamp' => now()->addHours(3)]);
        }

        // request fcl orders system to save data
        $url = config('app.save_shipments');

        $helpers = new Helpers();
        $res = $helpers->send_curl($url, json_encode($headers));

        return response()->json($res);
    }

    public function fetchSaveShipmentLines()
    {
        $lines = DB::connection('bc240')->table('FCL1$Sales Invoice Line$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->where('a.Document No_', '!=', '')
            ->whereNotNull('a.No_')
            ->where('a.No_', '!=', '')
            ->where('a.No_', '!=', '41990')
            ->whereDate('a.Shipment Date', today())
            ->select(
                'a.Document No_ as shipment_no',
                'a.No_ as item_code',
                'a.Quantity as quantity',
                'a.Unit of Measure Code as unit_measure'
            )
            ->get();

        if (empty($lines)) {
            return response()->json(['success' => true, 'message' => 'No Shipment lines data to insert.', 'timestamp' => now()->addHours(3)]);
        }

        // request fcl orders system to save data
        $url = config('app.save_shipments_lines');

        $helpers = new Helpers();
        $res = $helpers->send_curl($url, json_encode($lines));

        return response()->json($res);
    }

    public function fetchDocwynDataAndSave(Request $request)
    {
        $company = $request->has('company') ? $request->company : 'FCL';
        $receivedDate = Carbon::today()->toDateString();
        $key = config('app.docwyn_api_key');
        $from = 0;
        $to = 100;

        $customers = [404, 240, 258, 913, 914, 420, 823, 824];

        foreach ($customers as $customer) {
            $fromRange = $from;
            $toRange = $to;

            do {
                $response = Http::get(config('app.fetch_save_docwyn_api') . $key . '&company=' . $company . '&recieved_date=' . $receivedDate . '&cust_no=' . $customer . '&from=' . $fromRange . '&to=' . $toRange);

                $responseData = $response->json();

                if (empty($responseData)) {
                    break;
                }

                $extdocItem = '';
                // $arrays_to_insert = [];
                $arrays_to_insert240 = [];

                $collection = collect($responseData);

                Log::info('DocWyn Data fetched for insert: ' . now()->addHours(3));

                $sortedData = $collection->sortBy('ext_doc_no')->sortBy('item_no')->values();

                foreach ($sortedData as $data) {
                    if (!is_array($data) || !array_key_exists('ext_doc_no', $data)) {
                        continue;
                    }

                    if ($extdocItem == $data['ext_doc_no'] . $data['item_no']) {
                        continue;
                    }

                    // $arrays_to_insert[] = [
                    //     'company' => $data['company'],
                    //     'cust_no' => $data['cust_no'],
                    //     'cust_spec' => $data['cust_spec'],
                    //     'ext_doc_no' => $data['ext_doc_no'],
                    //     'item_no' => $data['item_no'],
                    //     'item_spec' => $data['item_spec'],
                    //     'line_no' => $data['line_no'],
                    //     'quantity' => abs(intval($data['quantity'])),
                    //     'shp_code' => $data['shp_code'],
                    //     'shp_date' => Carbon::parse($data['shp_date'])->format('Y-m-d H:i:s.u'),
                    //     'sp_code' => $data['sp_code'],
                    //     'uom_code' => '',
                    // ];

                    $arrays_to_insert240[] = [
                        'Company' => $data['company'],
                        'Sell-to Customer No_' => $data['cust_no'],
                        'Customer Specification' => $data['cust_spec'],
                        'External Document No_' => $data['ext_doc_no'],
                        'Item No_' => $data['item_no'],
                        'Line No_' => $data['line_no'],
                        'Quantity' => abs(intval($data['quantity'])),
                        'Ship-to Code' => $data['shp_code'],
                        'Shipment Date' => Carbon::parse($data['shp_date'])->format('Y-m-d H:i:s'),
                        'Salesperson Code' => $data['sp_code'],
                        'Unit of Measure' => '',
                    ];

                    $extdocItem = $data['ext_doc_no'] . $data['item_no'];
                }

                try {
                    // if (!empty($arrays_to_insert)) {
                    //     DB::connection('pickAndPack')->table('imported_orders')->upsert($arrays_to_insert, ['item_no', 'ext_doc_no']);
                    // }
                    if (!empty($arrays_to_insert240)) {
                        log::info('DocWyn Data saved for insert: '. json_encode($arrays_to_insert240));
                        DB::connection('bc240')->table('FCL1$Imported Orders$23dc970e-11e8-4d9b-8613-b7582aec86ba')->upsert($arrays_to_insert240, ['Item No_', 'External Document No_']);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception in ' . __METHOD__ . '(): ' . $e->getMessage());
                    return response()->json(['error' => $e->getMessage(), 'action' => 'Docwyn fetch & Insert', 'timestamp' => now()->addHours(3)]);
                }

                $fromRange = $toRange + 1;
                $toRange = $fromRange + 100;

            } while (count($responseData) > 0);
        }

        return response()->json(['message' => 'Data saved successfully', 'action' => 'Docwyn fetch & Insert', 'timestamp' => now()->addHours(3)]);
    }

    public function fetchAndSaveShopInvoices()
    {
        $url = config('app.fetch_shop_invoices_api');

        $helpers = new Helpers();
        $response = $helpers->send_curl($url, $post_data = null);

        if (empty($response)) {
            return response()->json(['success' => true, 'message' => 'No data to insert invoices.', 'timestamp' => now()->addHours(3)]);
        }

        // Insert the results into the new database
        $invoices = json_decode($response, true);
        $extNos = array_column($invoices, 'extdocno');

        try {
            DB::beginTransaction();

            foreach ($invoices as $invoice) {
                // Insert the data into the new table
                $extDocNo = strtoupper($invoice['extdocno']);
                $lineNo = $invoice['line_no'];

                // Check if the combination exists in the table
                $existingRecord = DB::connection('bc240')->table('FCL1$Imported Sales$23dc970e-11e8-4d9b-8613-b7582aec86ba')
                    ->where('ExtDocNo', $extDocNo)
                    ->where('LineNo', $lineNo)
                    ->first();

                if (!$existingRecord) {
                    DB::connection('bc240')->table('FCL1$Imported Sales$23dc970e-11e8-4d9b-8613-b7582aec86ba')->insert([
                        'ExtDocNo' => $extDocNo,
                        'LineNo' => $lineNo,
                        'ItemNo' => $invoice['item_code'],
                        'CustNO' => $invoice['cust_no'],
                        'Date' => $invoice['date'],
                        'ShiptoCOde' => '',
                        'Location' => '',
                        'ShiptoName' => '',
                        'SUOM' => '',
                        'SPCode' => $invoice['shop_code'],
                        'Qty' => $invoice['qty'],
                        'UnitPrice' => $invoice['price'],
                        'LineAmount' => $invoice['line_amount'],
                        'TotalHeaderAmount' => $invoice['total_amt'],
                        'TotalHeaderQty' => $invoice['total_qty'],
                        'Type' => 2,
                        'Executed' => 0,
                        'Posted' => 0,
                        'ItemBlockedStatus' => 0,
                        'RevertFlag' => 0,
                    ]);
                }
            }

            // Update the is_imported column in the original table
            $url = config('app.update_imported_invoices');
            $response = $helpers->send_curl($url, json_encode($extNos));

            DB::commit(); // Commit the transaction if everything is successful
            return response()->json(['success' => true, 'action' => 'Shop Invoices synced successfully', 'timestamp' => now()->addHours(3)]);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction if an exception occurs

            // Handle the exception (log, throw, or other custom logic)
            Log::error('Shop Invoices Transaction failed: ' . $e->getMessage());
            return response()->json(['Error' => $e->getMessage(), 'action' => 'Shop Invoices sync failed', 'timestamp' => now()->addHours(3)]);
        }
    }
    public function fetchAndSaveShopInvoicesCustom()
    {
        $url = config('app.fetch_shop_invoices_api_custom');

        $helpers = new Helpers();
        $response = $helpers->send_curl($url, $post_data = null);

        if (empty($response)) {
            return response()->json(['success' => true, 'message' => 'No data to insert invoices.', 'timestamp' => now()->addHours(3)]);
        }

        // Insert the results into the new database
        $invoices = json_decode($response, true);
        $extNos = array_column($invoices, 'extdocno');

        try {
            DB::beginTransaction();

            foreach ($invoices as $invoice) {
                // Insert the data into the new table
                $extDocNo = strtoupper($invoice['extdocno']);
                $lineNo = $invoice['line_no'];

                // Check if the combination exists in the table
                $existingRecord = DB::connection('bc240')->table('FCL1$Imported Sales$23dc970e-11e8-4d9b-8613-b7582aec86ba')
                    ->where('ExtDocNo', $extDocNo)
                    ->where('LineNo', $lineNo)
                    ->first();

                if (!$existingRecord) {
                    DB::connection('bc240')->table('FCL1$Imported Sales$23dc970e-11e8-4d9b-8613-b7582aec86ba')->insert([
                        'ExtDocNo' => $extDocNo,
                        'LineNo' => $lineNo,
                        'ItemNo' => $invoice['item_code'],
                        'CustNO' => $invoice['cust_no'],
                        'Date' => $invoice['date'],
                        'ShiptoCOde' => '',
                        'Location' => '',
                        'ShiptoName' => '',
                        'SUOM' => '',
                        'SPCode' => $invoice['shop_code'],
                        'Qty' => $invoice['qty'],
                        'UnitPrice' => $invoice['price'],
                        'LineAmount' => $invoice['line_amount'],
                        'TotalHeaderAmount' => $invoice['total_amt'],
                        'TotalHeaderQty' => $invoice['total_qty'],
                        'Type' => 2,
                        'Executed' => 0,
                        'Posted' => 0,
                        'ItemBlockedStatus' => 0,
                        'RevertFlag' => 0,
                    ]);
                }
            }

            // Update the is_imported column in the original table
            $url = config('app.update_imported_invoices');
            $response = $helpers->send_curl($url, json_encode($extNos));

            DB::commit(); // Commit the transaction if everything is successful
            return response()->json(['success' => true, 'action' => 'Shop Invoices synced successfully', 'timestamp' => now()->addHours(3)]);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction if an exception occurs

            // Handle the exception (log, throw, or other custom logic)
            Log::error('Shop Invoices Transaction failed: ' . $e->getMessage());
            return response()->json(['Error' => $e->getMessage(), 'action' => 'Shop Invoices sync failed', 'timestamp' => now()->addHours(3)]);
        }
    }

    public function fetchUpdateInvoicesSignatures()
    {
        $blank_invoices = DB::connection('bc240')->table('FCL1$Sales Invoice Header$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            // ->join('FCL$Sales Invoice Header as b', function ($join) {
            //     $join->on('a.No_', '=', DB::raw('UPPER(b.No_)'));
            // })
            ->select('a.External Document No_')
            ->whereDate('a.Posting Date', '>=', today()->subDays(2)) //last 2 days invoices
            ->where('a.CUInvoiceNo', '')
            ->where('a.External Document No_', 'like', 'IV-%')
            ->get()
            ->pluck('External Document No_')
            ->toArray();

        $url = config('app.fetch_invoices_signature_api');

        $helpers = new Helpers();

        $response = $helpers->send_curl($url, json_encode($blank_invoices));

        if (empty($response)) {
            return response()->json(['success' => true, 'message' => 'No data to update signatures.', 'timestamp' => now()->addHours(3)]);
        }

        $toUpdateData = json_decode($response, true);

        try {

            DB::beginTransaction();

            foreach ($toUpdateData as $b) {
                $updateQuery = DB::connection('bc240')->table('FCL1$Sales Invoice Header$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
                    // ->join('FCL$Sales Invoice Header as b', function ($join) {
                    //     $join->on('a.No_', '=', DB::raw('UPPER(b.No_)'));
                    // })
                    ->where('a.External Document No_', $b['External_doc_no'])
                    ->update([
                        'a.SignTime' => $b['SignTime'],
                        'a.CUNo' => $b['CuNo'],
                        'a.CUInvoiceNo' => $b['CuInvoiceNo']
                    ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'action' => 'fetchUpdateInvoicesSignatures()', 'timestamp' => now()->addHours(3)]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exception in fetchUpdateInvoicesSignatures(): ' . $e->getMessage());
            return response()->json(['Error' => $e->getMessage(), 'action' => 'fetchUpdateInvoicesSignatures()', 'timestamp' => now()->addHours(3)]);
        }
    }

    public function fetchUpdateSpecificInvoicesSignatures()
    {
        $url = config('app.fetch_specific_invoices_signature_api');

        $helpers = new Helpers();

        $response = $helpers->send_curl($url);

        if (empty($response)) {
            return response()->json(['success' => true, 'message' => 'No data to update signatures.', 'timestamp' => now()->addHours(3)]);
        }

        $toUpdateData = json_decode($response, true);

        try {

            DB::beginTransaction();

            foreach ($toUpdateData as $b) {
                $updateQuery = DB::connection('bc240')->table('FCL1$Sales Invoice Header$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
                    // ->join('FCL$Sales Invoice Header as b', function ($join) {
                    //     $join->on('a.No_', '=', DB::raw('UPPER(b.No_)'));
                    // })
                    ->where('a.External Document No_', $b['External_doc_no'])
                    ->update([
                        'a.SignTime' => $b['SignTime'],
                        'a.CUNo' => $b['CuNo'],
                        'a.CUInvoiceNo' => $b['CuInvoiceNo']
                    ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'action' => 'action at ' . __METHOD__ . '', 'timestamp' => now()->addHours(3)]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exception in ' . __METHOD__ . ': ' . $e->getMessage());
            return response()->json(['Error' => $e->getMessage(), 'action' => 'action at' . __METHOD__ . '', 'timestamp' => now()->addHours(3)]);
        }
    }

    public function fetchInsertPortalCustomers()
    {
        $customers = DB::connection('bc240')->table('FCL1$Customer$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->select('a.No_ as customer_no', 'a.Name as customer_name', 'a.Phone No_ as customer_phone')
            ->where('a.Web Portal', 1)
            ->distinct()
            ->get();

        $url = config('app.insert_portal_customers_api');

        $helpers = new Helpers();

        $response = $helpers->send_curl($url, json_encode($customers));

        return response()->json($response);
    }

    public function fetchInsertPortalCustomersAddresses()
    {
        $addresses = DB::connection('bc240')
            ->table('FCL1$Ship-to Address$437dbf0e-84ff-417a-965d-ed2bb9650972 as a')
            ->select('a.Customer No_ as customer_no', DB::raw("'' as route_code"), 'a.Code as ship_code', 'a.Name as ship_to_name')
            ->whereIn('a.Customer No_', function ($query) {
                $query->select('d.No_')
                    ->from('FCL$Customer as d')
                    ->where('d.Web Portal', 1);
            })
            ->where('a.Name', '!=', '')
            ->distinct()
            ->orderBy('a.Customer No_')
            ->get();

        $url = config('app.insert_portal_shipping_addresses_api');

        $helpers = new Helpers();

        $response = $helpers->send_curl($url, json_encode($addresses));

        return response()->json($response);
    }

    public function fetchMpesaPayments()
    {
        $url = config('app.fetch_mpesa_transactions_api');
        // $data = Http::timeout(60)->post($url);
        return $url;
    }

    public function insertMpesaPayments()
    {
        $data = $this->fetchMpesaPayments();

        return $data;

        try {
            //code...
            foreach ($data as $d) {
                DB::connection('sales')
                ->table('FCL$MPESA Confirmation')
                ->upsert(
                    [
                        [
                            'Confirmation Code' => $d->TransID,
                            'Short Code' => $d->BusinessShortCode,
                            'Name' => $d->FirstName,
                            'Amount' => $d->TransAmount,
                            'Date' => $d->TransTime,
                        ]
                    ], 
                    ['Confirmation Code'], // Columns to check for conflict
                    ['Short Code', 'Name', 'Amount', 'Date'] // Columns to update if conflict occurs
                );
            }

            return response()->json(['success' => true, 'action' => 'action at ' . __METHOD__ . '', 'timestamp' => now()->addHours(3)]);
        } catch (\Exception $e) {
            Log::error('Exception in ' . __METHOD__ . ': ' . $e->getMessage());
            return response()->json(['Error' => $e->getMessage(), 'action' => 'action at' . __METHOD__ . '', 'timestamp' => now()->addHours(3)]);
        }
    }
}
