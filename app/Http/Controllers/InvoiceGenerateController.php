<?php
namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\OrganizationCardPurchase;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InvoiceGenerateController extends Controller
{
    public function sendInvoice(OrganizationCardPurchase $purchase)
    {
        try {

            $purchase->load('organization');

            $invoiceNo = $this->generateInvoiceNo($purchase);

            $pdf = Pdf::loadView('emails.invoice', [
                'purchase'  => $purchase,
                'invoiceNo' => $invoiceNo,
            ]);

            $fileName = 'invoices/invoice-' . $purchase->id . '.pdf';

            Storage::disk('public')->put(
                $fileName,
                $pdf->output()
            );

            // Mail::to($purchase->organization->email)
            //     ->send(new InvoiceMail($purchase, $fileName));

            Mail::to($purchase->organization->email)
                ->send(new InvoiceMail(
                    $purchase,
                    $fileName,
                    $invoiceNo
                ));

            return response()->json([
                'success' => true,
                'message' => 'Invoice sent successfully',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadInvoice(OrganizationCardPurchase $purchase)
    {
        $purchase->load('organization');

        $invoiceNo = $this->generateInvoiceNo($purchase);

        \Log::info('Invoice Download Data', [
            'invoice_no' => $invoiceNo,
            'purchase'   => $purchase->toArray(),
        ]);

        $pdf = Pdf::loadView('emails.invoice', [
            'purchase'  => $purchase,
            'invoiceNo' => $invoiceNo,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream("{$invoiceNo}.pdf");
    }

    private function generateInvoiceNo($purchase)
    {
        $date = $purchase->created_at;

        $year  = $date->year;
        $month = $date->month;

        if ($month >= 4) {
            $fy = substr($year, -2) . substr($year + 1, -2);
        } else {
            $fy = substr($year - 1, -2) . substr($year, -2);
        }

        return 'MI' . $fy . 'INV-' . str_pad($purchase->id, 3, '0', STR_PAD_LEFT);
    }
}
