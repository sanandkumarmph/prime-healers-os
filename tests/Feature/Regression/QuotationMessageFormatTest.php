<?php

namespace Tests\Feature\Regression;

use App\Models\Quotation;
use App\Services\Sales\QuotationSharing;
use Tests\TestCase;

class QuotationMessageFormatTest extends TestCase
{
    private function quote(array $changes=[]): Quotation
    {
        $snapshot=['issuer'=>['name'=>'Synthetic Legal Company Ltd'], 'recipient'=>['name'=>'Synthetic prospect'],
            'sender'=>['name'=>'Synthetic Salesperson','company'=>'Synthetic Legal Company Ltd','phone'=>'+918000000001'],
            'currency'=>'INR','tax_label'=>'GST','start_date'=>'2026-11-03','end_date'=>'2026-12-02','transport'=>600,'deposit'=>2000,
            'total'=>4250,'payable_with_deposit'=>6250,'lines'=>[
                ['name'=>'Synthetic concentrator','line_type'=>'rental','quantity'=>1,'rate'=>3500,'total'=>3500,'tax_mode'=>'inclusive','tax_rate'=>5],
                ['name'=>'Synthetic nasal cannula','line_type'=>'sale','quantity'=>1,'rate'=>150,'total'=>150,'tax_mode'=>'inclusive','tax_rate'=>5],
            ]];
        return new Quotation(['enquiry_id'=>42,'revision'=>1,'kind'=>'rental','valid_until'=>'2026-11-09','snapshot'=>array_replace($snapshot,$changes)]);
    }

    public function test_whatsapp_layout_separates_rental_purchases_tax_deposit_and_sender_without_changing_snapshot(): void
    {
        $q=$this->quote();$before=$q->snapshot;
        $text=app(QuotationSharing::class)->message($q,'https://example.test/private-quotation');
        foreach (['*Prime Healers*','*Rental quotation · Q-00042-R1*','Hi Synthetic prospect,','*Rental — 30 days*',
            '*One-time purchases*','Rental & items: ₹3,650.00','*Quotation total: ₹4,250.00*','Refundable security deposit: ₹2,000.00',
            '*Total including deposit: ₹6,250.00*','_Product prices include GST._',"Regards,\nSynthetic Salesperson"] as $part) {
            $this->assertStringContainsString($part,$text);
        }
        $this->assertSame(1,substr_count($text,'Synthetic Legal Company Ltd'));
        $this->assertSame(1,substr_count($text,'https://example.test/private-quotation'));
        $this->assertStringNotContainsString(' | ',$text);$this->assertStringNotContainsString('%',$text);$this->assertSame($before,$q->snapshot);
        if ($folder=getenv('PHOS_QUOTE_MESSAGE_PREVIEW_DIR')) {
            if(!is_dir($folder))mkdir($folder,0700,true);
            file_put_contents($folder.'/synthetic-whatsapp-message.txt',$text);
        }
    }

    public function test_exclusive_and_mixed_tax_rates_keep_rate_and_tax_treatment_clear(): void
    {
        $lines=$this->quote()->snapshot['lines'];
        $lines[0]=array_replace($lines[0],['rate'=>100,'total'=>118,'tax_mode'=>'exclusive','tax_rate'=>18]);
        $text=app(QuotationSharing::class)->message($this->quote(['lines'=>$lines,'total'=>868,'payable_with_deposit'=>2868]),'https://example.test/q');
        $this->assertStringContainsString('1 × ₹100.00 + GST = *₹118.00*',$text);
        $this->assertStringContainsString('(GST included)',$text);
        $this->assertStringNotContainsString('_Product prices include GST._',$text);
        $this->assertStringNotContainsString('%',$text);
        $this->assertStringContainsString('Rental & items: ₹268.00',$text);
        $this->assertStringContainsString('*Quotation total: ₹868.00*',$text);
    }

    public function test_sales_and_legacy_snapshots_do_not_invent_rental_deposit_or_sender_details(): void
    {
        $line=$this->quote()->snapshot['lines'][1];unset($line['line_type']);
        $q=$this->quote(['currency'=>'USD','tax_label'=>'Tax','lines'=>[$line]]);$q->kind='sale';
        $s=$q->snapshot;unset($s['sender']);$q->snapshot=$s;
        $text=app(QuotationSharing::class)->message($q,'https://example.test/q');
        $this->assertStringContainsString('*Sales quotation · Q-00042-R1*',$text);
        $this->assertStringContainsString('*Products*',$text);$this->assertStringContainsString('USD 150.00',$text);
        $this->assertStringNotContainsString('Refundable security deposit',$text);$this->assertStringNotContainsString('Regards,',$text);
        $this->assertStringNotContainsString('Rental —',$text);
    }

    public function test_dynamic_labels_cannot_insert_whatsapp_sections_and_legal_name_is_not_repeated(): void
    {
        $q=$this->quote(['recipient'=>['name'=>"*Injected*\n_Total_"], 'issuer'=>['name'=>'Prime Healers'],
            'sender'=>['name'=>'Agent','company'=>'Prime Healers','phone'=>'+918000000001']]);
        $text=app(QuotationSharing::class)->message($q,'https://example.test/q');
        $this->assertStringNotContainsString('*Injected*',$text);$this->assertStringNotContainsString('_Total_',$text);
        $this->assertSame(1,substr_count($text,'Prime Healers'));
    }
}
