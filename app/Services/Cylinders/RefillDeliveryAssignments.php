<?php
namespace App\Services\Cylinders;

use App\Models\{Staff,User,Vendor};
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

class RefillDeliveryAssignments
{
    public const TYPES=['delivery_team'=>'Internal team','third_party'=>'Third party','vendor'=>'Vendor'];

    public static function rules(): array
    {
        return ['assignment_type'=>'sometimes|required|in:delivery_team,third_party,vendor',
            'assignment_target'=>['nullable','string','regex:/^(?:(?:user|staff|vendor):[1-9][0-9]{0,9}|manual)$/'],
            'assigned_user_id'=>'nullable|integer','third_party_name'=>'nullable|string|max:255',
            'third_party_contact'=>'nullable|string|max:255','third_party_phone'=>PhoneNumber::validationRules()];
    }
    public static function userType(User $user): ?string
    {
        return match($user->effective_role){
            User::ROLE_DELIVERY,User::ROLE_DELIVERY_EXECUTIVE,'pickup'=>'delivery_team',
            User::ROLE_VENDOR=>'vendor',User::ROLE_THIRD_PARTY=>'third_party',default=>null,
        };
    }
    public function options(User $actor): array
    {
        $all=$actor->scopeType('deliveries')==='all';$options=[];
        foreach(User::with('assignedRole')->where('organization_id',$actor->organization_id)->orderBy('name')->get() as $user){
            $type=self::userType($user);
            if(!$type||!$user->canAccessModule('deliveries','read')||!$user->canAccessModule('deliveries','update')||(!$all&&$user->id!==$actor->id))continue;
            $options[]=['value'=>'user:'.$user->id,'type'=>$type,'label'=>$user->name,'login'=>true];
        }
        if($all){
            foreach(Staff::forOrganization($actor->organization_id)->eligibleForAssignments()->orderBy('name')->get() as $staff){
                if(!in_array($staff->effective_role,['vendor','third_party'],true))continue;
                $options[]=['value'=>'staff:'.$staff->id,'type'=>$staff->effective_role,'label'=>$staff->name.' · Staff record','login'=>false];
            }
            foreach(Vendor::forOrganization($actor->organization_id)->where('is_active',true)->orderBy('name')->get() as $vendor)
                $options[]=['value'=>'vendor:'.$vendor->id,'type'=>'vendor','label'=>$vendor->name.' · Business partner','login'=>false];
            $options[]=['value'=>'manual','type'=>'third_party','label'=>'Enter person / transporter details','login'=>false];
        }
        return $options;
    }
    private function fail(string $text): never {throw ValidationException::withMessages(['assignment_target'=>$text]);}
    public function resolve(User $actor,array $data): array
    {
        $type=$data['assignment_type']??'delivery_team';
        if(!isset(self::TYPES[$type]))$this->fail('Choose Internal team, Third party or Vendor.');
        // Older cached forms can still send an internal delivery user id.
        $target=$data['assignment_target']??(filled($data['assigned_user_id']??null)?'user:'.$data['assigned_user_id']:'');
        if(!preg_match('/^(?:(user|staff|vendor):([1-9][0-9]{0,9})|(manual))$/',$target,$match))$this->fail('Choose who will handle this delivery.');
        if(!collect($this->options($actor))->contains(fn($option)=>$option['value']===$target&&$option['type']===$type))
            $this->fail('Choose an active assignee from the selected delivery type.');
        $result=['assignment_type'=>$type,'assigned_user_id'=>null,'assigned_staff_id'=>null,'assigned_vendor_id'=>null,
            'assignment_name'=>null,'assignment_contact'=>null,'assignment_phone'=>null];
        if($target==='manual'){
            $name=trim($data['third_party_name']??'');if($name==='')$this->fail('Enter the third-party person or transporter name.');
            $result['assignment_name']=$name;$result['assignment_contact']=trim($data['third_party_contact']??'')?:null;
            $result['assignment_phone']=PhoneNumber::normalize($data['third_party_phone']??null);
        }else{
            [$kind,$id]=explode(':',$target);
            $record=match($kind){'user'=>User::class,'staff'=>Staff::class,'vendor'=>Vendor::class};
            $record=$record::where('organization_id',$actor->organization_id)->lockForUpdate()->findOrFail($id);
            // Recheck after locking so deactivation/role changes cannot enter a new assignment.
            if($kind==='user'&&(!$record->canAccessModule('deliveries','read')||!$record->canAccessModule('deliveries','update')||self::userType($record)!==$type))$this->fail('This delivery login is no longer available.');
            if($kind==='staff'&&(!$record->assignment_eligible||$record->effective_role!==$type))$this->fail('This staff record is no longer available.');
            if($kind==='vendor'&&!$record->is_active)$this->fail('This vendor is no longer active.');
            $result[match($kind){'user'=>'assigned_user_id','staff'=>'assigned_staff_id','vendor'=>'assigned_vendor_id'}]=(int)$record->id;
            $result['assignment_name']=$record->name;$result['assignment_phone']=$record->phone;
            $result['assignment_contact']=$kind==='vendor'?$record->contact_person:null;
        }
        if($actor->scopeType('deliveries')!=='all'&&(int)($result['assigned_user_id']??0)!==(int)$actor->id)abort(403);
        return $result;
    }
}
