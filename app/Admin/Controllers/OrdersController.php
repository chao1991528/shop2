<?php

namespace App\Admin\Controllers;

use App\Models\Order;
use App\Exceptions\InvalidRequestException;
use App\Exceptions\InternalException;
use Illuminate\Http\Request;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\ModelForm;
use App\Http\Requests\Admin\HandleRefundRequest;

class OrdersController extends Controller
{
    use ModelForm;

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index()
    {
        return Admin::content(function (Content $content) {

            $content->header('订单列表');

            $content->body($this->grid());
        });
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(Order::class, function (Grid $grid) {
            $grid->model()->whereNotNull('paid_at')->orderBy('paid_at', 'desc');
            $grid->no('订单号');
            $grid->column('user.name', '买家');
            $grid->total_amount('订单金额');
            $grid->payment_method('支付方式');
            $grid->ship_status('发货状态')->display(function($value){
                return Order::$shipStatusMap[$value];
            });
            $grid->refund_status('退款状态')->display(function($value){
                return Order::$refundStatusMap[$value];
            });
            $grid->paid_at('支付时间');
            $grid->created_at('下单时间');

            // 禁用创建按钮，后台不需要创建订单
            $grid->disableCreateButton();
            $grid->actions(function ($actions) {
                // 禁用删除和编辑按钮
                $actions->disableDelete();
                $actions->disableEdit();
                $actions->append('<a class="btn btn-xs btn-primary" href="'.route('admin.orders.show', [$actions->getKey()]).'">查看</a>');
            });
            $grid->tools(function ($tools) {
                // 禁用批量删除按钮
                $tools->batch(function ($batch) {
                    $batch->disableDelete();
                });
            });
        });
    }

    public function show(Order $order)
    {
        return Admin::content(function (Content $content) use ($order) {

            $content->header('查看订单');
            $content->description('description');

            $content->body(view('admin.orders.show', ['order' => $order]));
        });
    }

    public function ship(Order $order, Request $request)
    {
        //判断当前订单是否已经支付
        if(!$order->paid_at){
            throw new InvalidRequestException("该订单未支付");
        }
        //判断该订单是否已经发货
        if($order->ship_status !== Order::SHIP_STATUS_PENDING){
            throw new InvalidRequestException("订单已经发货");
        }
        //数据验证
        $data = $this->validate($request, [
            'express_company'   => 'required',
            'express_no'        => 'required',
        ], [], [
            'express_company' => '物流公司',
            'express_no'      => '物流单号'
        ]);
        //将物流信息写入订单
        $order->update([
            'ship_status' => Order::SHIP_STATUS_DELIVERED,
            // 我们在 Order 模型的 $casts 属性里指明了 ship_data 是一个数组
            // 因此这里可以直接把数组传过去
            'ship_data'   => $data
        ]);

        //返回上一页
        return redirect()->back();

    }

    public function handleRefund(Order $order, HandleRefundRequest $request)
    {
        //判断订单的退款状态
        if($order->refund_status !== Order::REFUND_STATUS_APPLIED){
            throw new InvalidRequestException('退款状态不正确');
        }
        //是否同意退款
        if($request->input('agree')){
            $this->_refundOrder($order);
        } else {
            //将拒绝退款理由写入extra
            $extra = $order->extra ?: [];
            $extra['refund_disagree_reason'] = $request->input('reason');
            //更新订单
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'extra' => $extra
            ]);
        }

        return $order;
    }

    //同意退款
    public function _refundOrder(Order $order)
    {
        switch ($order->payment_method) {
            case 'wechat':
                # code...
                break;
            case 'alipay':
                $refund_no = $order->getAvailableRefundNo();

                $ret = app('alipay')->refund([
                    'out_trade_no' => $order->no,
                    'refund_amount' => $order->total_amount,
                    'out_request_no' => $refund_no // 退款订单号
                ]);
                //根据支付宝的文档，如果返回值里有 sub_code 字段说明退款失败
                if($ret->sub_code){
                    //将退款失败原因写入到extra
                    $extra = $order->extra ?:[];
                    $extra['refund_failed_code'] = $ret->sub_code;
                    //将退款状态标记为退款失败
                    $order->update([
                        'refund_no' => $refund_no,
                        'refund_status' => Order::REFUND_STATUS_FAILED,
                        'extra' => $extra
                    ]);

                } else {
                    $order->update([
                        'refund_no' => $refund_no,
                        'refund_status' => Order::REFUND_STATUS_SUCCESS
                    ]);
                }
                break;
            default:
                // 原则上不可能出现，这个只是为了代码健壮性
                throw new InternalException('未知订单支付方式：'.$order->payment_method);
                break;
        }
    }
}
