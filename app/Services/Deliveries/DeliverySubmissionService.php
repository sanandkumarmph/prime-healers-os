<?php

namespace App\Services\Deliveries;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DeliverySubmissionService
{
    /** Called inside PHOS's organisation transaction, before controller reads. */
    public function run(Request $request, callable $next): Response
    {
        $action = $request->route()?->getActionMethod();
        if (!in_array($action, ['markInProgress','markCompleted','recordPartialDelivery','recordPartialPickup'], true)
            || !$request->filled('workflow_submission_token')) return $next();

        $request->validate(['workflow_submission_token'=>'required|uuid']);
        $task = $request->route('delivery');
        abort_unless($task instanceof Delivery && (int) $task->organization_id === (int) $request->user()->organization_id, 404);
        Gate::authorize('update',$task);
        $token = strtolower($request->input('workflow_submission_token'));
        $values = $request->except(['_token','workflow_submission_token']);
        $hash = hash('sha256',json_encode([$task->id,$request->user()->id,$action,$this->normalize($values)],JSON_THROW_ON_ERROR));
        $saved = DB::table('delivery_workflow_submissions')->where('organization_id',$task->organization_id)->where('submission_token',$token)->first();
        if ($saved) {
            if (!hash_equals($saved->request_hash,$hash) || (int)$saved->user_id !== (int)$request->user()->id) {
                throw ValidationException::withMessages(['workflow_submission_token'=>'This submission token has already saved a different action. Review task history before submitting new evidence or quantities.']);
            }
            return redirect()->to($saved->redirect_url,$saved->response_status)->with('success','This task action was already saved. No quantities or evidence were recorded again.');
        }

        $response = $next();
        if ($response->isRedirection() && !($response->exception ?? null)
            && !($request->hasSession() && (in_array('error',$request->session()->get('_flash.new',[]),true) || $request->session()->has('errors')))) {
            $location=$response->headers->get('Location',route('deliveries.show',$task));
            if (parse_url($location,PHP_URL_HOST) && parse_url($location,PHP_URL_HOST) !== $request->getHost()) $location=route('deliveries.show',$task);
            DB::table('delivery_workflow_submissions')->insert(['organization_id'=>$task->organization_id,'delivery_id'=>$task->id,
                'user_id'=>$request->user()->id,'submission_token'=>$token,'action'=>$action,'request_hash'=>$hash,
                'response_status'=>$response->getStatusCode(),'redirect_url'=>$location,'created_at'=>now(),'updated_at'=>now()]);
        }
        return $response;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) return ['file_name'=>$value->getClientOriginalName(),'sha256'=>hash_file('sha256',$value->getRealPath())];
        if (is_array($value)) { ksort($value); return array_map(fn ($item)=>$this->normalize($item),$value); }
        return $value;
    }
}
