<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Utilities\Constant;
use App\Utilities\VNPay;
use Illuminate\Http\Request;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Mail;

class CheckOutController extends Controller
{
    //
    public function index() {

        $carts = Cart::content();
        $total = Cart::total();
        $subtotal = Cart::subtotal();

        return view('front.checkout.index', compact('carts', 'total', 'subtotal'));
    }

    public function addOrder(Request $request)
    {
        //1. Thêm đơn hàng
        $data = $request->all();
        $data['status'] = Constant::order_status_ReceiveOrders;
        $order = Order::create($data);

        //2. Thêm chi tiết đơn hàng
        $carts = Cart::content();

        foreach ($carts as $cart) {
            $data = [
                'order_id' => $order->id,
                'product_id' => $cart->id,
                'qty' => $cart->qty,
                'amount' => $cart->price,
                'total' => $cart->price * $cart->qty,

            ];
            OrderDetail::create($data);
        }

        if ($request->payment_type == 'pay_later') {
            //3. Gửi email
            $total = Cart::total();
            $subtotal = Cart::subtotal();
            $this->sendEmail($order, $total, $subtotal);

            //4. Xóa giỏ hàng
            Cart::destroy();

            //5. Trả về kết quả
            return redirect('checkout/result')->with('notification', 'Success! You will pay on delivery. Please check your email');
        }

        if ($request->payment_type == 'online_payment'){
            //01. Lấy URL thanh toán VNPay
            $data_url = VNPay::vnpay_create_payment([
                'vnp_TxnRef' => $order->id,
                'vnp_OrderInfo' => 'Mô tả về đơn hàng ở đây',
                'vnp_Amount' => Cart::total(0, '', '') * 23075,
            ]);

            //02. Chuyển hướng tới URL lấy được
            return redirect()->to($data_url);

        }

        else{
            return "online payment method is not supported";
        }
    }

    public function vnPayCheck(Request $request){
        $vnp_ResponseCode = $request->get('vnp_ResponseCode');
        $vnp_TxnRef = $request->get('vnp_TxnRef');
        $vnp_Amount = $request->get('vnp_Amount');

        if ($vnp_ResponseCode != null){
            if ($vnp_ResponseCode == 00){
                //Cập nhật trạng thái order
                $this->orderService->update(['status'=> Constant::order_status_Paid], $vnp_TxnRef);

                //gửi mail
                $order = Order::find($vnp_TxnRef);
                $total = Cart::total();
                $subtotal = Cart::subtotal();
                $this->sendEmail($order, $total, $subtotal);

                //xóa giỏ hàng
                Cart::destroy($order);

                //Thông báo kết quả
                return "Success! Has paid online.";
            } else {
                Order::find($vnp_TxnRef)->delete();
                return 'Error: Payment failed  or caceled';
            }
        }
    }
    private function sendEmail($order, $total, $subtotal) {
        $email_to = $order->email;

        Mail::send('front.checkout.email', compact('order', 'total', 'subtotal'), function ($message) use ($email_to) {
            $message->from('hoangcongtu53999@gmail.com', 'Edan Nguyễn');
            $message->to($email_to, $email_to);
            $message->subject('Edan Shop');
        });
    }

    public function  result() {

        $notification = session('notification');
        return view('front.checkout.result', compact('notification'));
    }





}
