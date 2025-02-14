<?php

namespace App\Http\Controllers\User\Payment;

use App\Classes\AppointmentBook;
use App\Models\Plan;
use App\Models\User;
use App\Models\Config;
use App\Models\Transaction;
use App\Classes\UpgradePlan;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingTransaction;
use App\Models\Business;
use App\Models\BusinessService;
use App\Models\Configuration;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class PhonepeController extends Controller
{
    public function preparePhonpe($bookingId)
    {
        if (Auth::check()) {

            // Queries
            $bookingDetails = Booking::where('booking_id', $bookingId)->first();
            $service_name = BusinessService::where('business_service_id', $bookingDetails->business_service_id)->first()->business_service_name;
            $config = Configuration::get();
            $setting = Setting::where('status', 1)->first();

            if ($bookingDetails == null) {
                return back();
            } else {
                $transactionId = uniqid();

                $service_amount = BusinessService::where('business_service_id', $bookingDetails->business_service_id)->first()->amount;

                $business_id = BusinessService::where('business_service_id', $bookingDetails->business_service_id)->first()->business_id;
                $business_user_id = Business::where('business_id', $business_id)->first()->user_id;
                $user = User::where('user_id', $business_user_id)->first();

                $planDetails = json_decode($user->plan_details, true); // Decoded as array            
                // Step 2: Decode plan_features since it's a nested JSON string
                $planFeatures = is_string($planDetails['plan_features'])
                    ? json_decode($planDetails['plan_features'], true)
                    : $planDetails['plan_features'];

                $payment_gateway_percentage = $planFeatures['payment_gateway_charge'];

                $payment_gateway_charge = round((float)($service_amount) * ($payment_gateway_percentage / 100), 2);

                $sub_total = (float)($service_amount) + (float)($payment_gateway_charge);

                // Paid amount
                $amountToBePaid = $bookingDetails->total_price;

                try {
                    // sta payment intent
                    $data = array(
                        'merchantId' => $config[53]->config_value,
                        'merchantTransactionId' => $transactionId,
                        'merchantUserId' => $config[53]->config_value,
                        'amount' => $amountToBePaid,
                        'redirectUrl' => route('booking.payment.phonepe.status'),
                        'redirectMode' => 'POST',
                        'callbackUrl' => route('booking.payment.phonepe.status'),
                        'mobileNumber' => $config[18]->config_value,
                        'paymentInstrument' =>
                        array(
                            'type' => 'PAY_PAGE',
                        ),
                    );

                    $encode = base64_encode(json_encode($data));

                    $saltKey = $config[54]->config_value;
                    $saltIndex = 1;

                    $string = $encode . '/pg/v1/pay' . $saltKey;
                    $sha256 = hash('sha256', $string);

                    $finalXHeader = $sha256 . '###' . $saltIndex;

                    $response = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'X-VERIFY' => $finalXHeader,
                    ])->post('https://api.phonepe.com/apis/hermes/pg/v1/pay', [
                        'request' => $encode,
                    ]);

                    $rData = json_decode($response);

                    if (isset($rData)) {
                        if ($rData->success == true) {
                            // Generate JSON
                            $invoice_details = [];

                            $invoice_details['from_billing_name'] = $config[16]->config_value;
                            $invoice_details['from_billing_address'] = $config[19]->config_value;
                            $invoice_details['from_billing_city'] = $config[20]->config_value;
                            $invoice_details['from_billing_state'] = $config[21]->config_value;
                            $invoice_details['from_billing_zipcode'] = $config[22]->config_value;
                            $invoice_details['from_billing_country'] = $config[23]->config_value;
                            $invoice_details['from_vat_number'] = $config[26]->config_value;
                            $invoice_details['from_billing_email'] = $config[17]->config_value;
                            $invoice_details['from_billing_phone'] = $config[18]->config_value;
                            $invoice_details['to_billing_name'] = Auth::user()->name;
                            $invoice_details['to_billing_email'] = Auth::user()->email;
                            $invoice_details['tax_name'] = $config[24]->config_value;
                            $invoice_details['tax_type'] = $config[14]->config_value;
                            $invoice_details['tax_value'] = (float)($config[25]->config_value);
                            $invoice_details['service_amount'] = $service_amount;
                            $invoice_details['payment_gateway_charge'] = (float)($payment_gateway_charge);
                            $invoice_details['subtotal'] = $sub_total;
                            $invoice_details['tax_amount'] = round((float)($service_amount) * (float)($config[25]->config_value) / 100, 2);
                            $invoice_details['invoice_amount'] = $bookingDetails->total_price;                           

                            // Store transaction details 
                            $booking_transaction = new BookingTransaction();
                            $booking_transaction->booking_transaction_id = $transactionId;
                            $booking_transaction->user_id = Auth::user()->user_id;
                            $booking_transaction->booking_id = $bookingDetails->booking_id;
                            $booking_transaction->payment_gateway_name = "Phonepay";
                            $booking_transaction->transaction_currency = $config[1]->config_value;
                            $booking_transaction->transaction_total = $bookingDetails->total_price;
                            $booking_transaction->description = $service_name . " Service";
                            $booking_transaction->transaction_date = now();
                            $booking_transaction->invoice_details = json_encode($invoice_details);
                            $booking_transaction->transaction_status = "pending";
                            $booking_transaction->save();

                            return redirect()->to($rData->data->instrumentResponse->redirectInfo->url);
                        } else {
                            return redirect()->route('user.my-bookings')->with('failed', trans('Payment failed!'));
                        }
                    } else {
                        return redirect()->route('user.my-bookings')->with('failed', trans('Payment failed!'));
                    }
                } catch (\Exception $e) {
                    return redirect()->route('user.my-bookings')->with('failed', trans('Payment failed!'));
                }
            }
        } else {
            return redirect()->route('login');
        }
    }

    public function phonepePaymentStatus(Request $request)
    {
        // Queries
        $config = Configuration::get();

        $input = $request->all();

        if (count($request->all()) > 0 && isset($input['transactionId'])) {

            $merchantId = $config[53]->config_value;
            $saltKey = $config[54]->config_value;
            $saltIndex = 1;

            $finalXHeader = hash('sha256', '/pg/v1/status/' . $merchantId . '/' . $input['transactionId'] . $saltKey) . '###' . $saltIndex;

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'accept' => 'application/json',
                'X-VERIFY' => $finalXHeader,
                'X-MERCHANT-ID' => $merchantId
            ])->get('https://api.phonepe.com/apis/hermes/pg/v1/status/' . $merchantId . '/' . $input['transactionId']);

            $res = json_decode($response);

            if ($res->code == "PAYMENT_SUCCESS") {
                // Plan upgrade
                $appointmentBook = new AppointmentBook;
                $appointmentBook->upgrade($input['transactionId'], $res);              

                // Redirect
                return redirect()->route('user.my-bookings')->with('failed', trans('Appointment Booked successfully!'));
            } else {
                return redirect()->route('user.my-bookings')->with('failed', trans('Payment failed!'));
            }
        } else {
            return redirect()->route('user.my-bookings')->with('failed', trans('Payment failed!'));
        }
    }
}
