<?php
/**
 * 城主管理
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018-08-28
 * Time: 15:11
 */

namespace app\wapp\controller;


use app\common\model\ApplyLeaderRecord;
use app\common\model\HeaderGroup;
use app\common\model\HeaderGroupProduct;
use app\common\model\HeaderGroupProductSwiper;
use app\common\model\WeiXinPay;
use think\Controller;
use think\Exception;
use think\Log;

class Header extends Controller
{
    private $header_id;

    protected function _initialize()
    {
        parent::_initialize();
        $this->header_id = input('header_id');
        $header = model('Header')->where('id', $this->header_id)->find();
        if (!$header) {
            $this->header_id = input('header_id');
            exit_json(-1, '城主不存在');
        }
    }

    /**
     * 新建团购/编辑团购
     */
    public function applyGroup()
    {
        //团购基础信息
        $data = [
            'group_title' => input('group_title'),
            'header_id' => input('header_id'),
            'group_notice' => input('group_notice'),
            'dispatch_type' => input('dispatch_type'),
            'dispatch_info' => input('dispatch_info'),
            'is_close' => input('is_close'),
            'status' => input('status'),
        ];
        $close_time = input('close_time');
        $close_time = date("Y-m-d H:i", strtotime($close_time));
        $data['close_time'] = $close_time;
        if (input('status') == 1) {
            $data['open_time'] = date('Y-m-d');
        }
        model('HeaderGroup')->startTrans();
        model('HeaderGroupProduct')->startTrans();
        model('HeaderGroupProductSwiper')->startTrans();
        try {
            $group_id = input('group_id');
            if ($group_id > 0) {
                $res1 = HeaderGroup::get($group_id)->save($data);
            } else {
                $res1 = model('HeaderGroup')->save($data);
                $group_id = model('HeaderGroup')->getLastInsID();
            }
            if (!$res1) {
                throw new Exception('创建团购失败');
            }
            //团购商品信息
            $product_list = input('product_list/a');
            foreach ($product_list as $key => $item) {
//            $item = json_decode($item, true);
                $base_id = $item['base_id'];
                if (!$base_id) {
                    //商品库不存在商品，增加商品库信息
                    model('Product')->data([
                        'product_name' => $item['product_name'],
                        'header_id' => input('header_id'),
                        'desc' => $item['product_desc']
                    ])->isUpdate(false)->save();
                    $base_id = model('Product')->getLastInsID();
                    $base_swiper = $item['img_list'];
                    $bs = [];
                    foreach ($base_swiper as $item1) {
                        $bs[] = [
                            'product_id' => $base_id,
                            'type' => $item1['types'],
                            'url' => $item1['urlImg']
                        ];
                    }
                    model('ProductSwiper')->saveAll($bs);
                }
                $product_data = [
                    'header_id' => $this->header_id,
                    'product_name' => $item['product_name'],
                    'header_group_id' => $group_id,
                    'base_id' => $base_id,
                    'remain' => $item['remain'] ? $item['remain'] : -1,
                    'commission' => $item['commission'],
                    'purchase_price' => $item['purchase_price'],
                    'market_price' => $item['market_price'],
                    'group_price' => $item['group_price'],
                    'group_limit' => $item['group_limit'],
                    'self_limit' => $item['self_limit'],
                    'ord' => $key,
                    'product_desc' => $item['product_desc'],
                ];
                if ($item['id']) {
                    $res2 = HeaderGroupProduct::update($product_data, ['id' => $item['id']]);
                } else {
                    $res2 = model('HeaderGroupProduct')->data($product_data)->isUpdate(false)->save();
                }
                if (!$res2) {
                    throw new Exception('商品添加失败');
                }
                if ($item['id']) {
                    $product_id = $item['id'];
                    HeaderGroupProductSwiper::destroy(['header_group_product_id' => $product_id]);
                } else {
                    $product_id = model('HeaderGroupProduct')->getLastInsID();
                }


                //二次添加商品处理加入团购商品列表
                $group_list = db()->query("select * from (SELECT * FROM ts_group_product WHERE  header_group_id = $group_id ORDER BY ord desc) a GROUP BY a.group_id");
                foreach ($group_list as $val) {
                    $g_product = model("GroupProduct")->where([
                        'header_group_id' => $group_id,
                        'header_product_id' => $product_id,
                        "leader_id"=>$val["leader_id"]
                    ])->find();
                    if ($g_product) {
                        $g_product->allowField(true)->save($product_data);
                    } else {
                        $data_temp = [
                            "leader_id" => $val['leader_id'],
                            'group_id' => $val['group_id'],
                            'product_name' => $product_data['product_name'],
                            'header_group_id' => $product_data['header_group_id'],
                            'commission' => $product_data['commission'],
                            'market_price' => $product_data['market_price'],
                            'group_price' => $product_data['group_price'],
                            'group_limit' => $product_data['group_limit'],
                            'self_limit' => $product_data['self_limit'],
                            'ord' => $val['ord'] + 1,
                            'product_desc' => $product_data['product_desc'],
                            'header_product_id' => $product_id
                        ];
                        model("GroupProduct")->data($data_temp)->isUpdate(false)->save();
                    }
                }
                //二次编辑添加商品处理结束


                $product_swiper = $item['img_list'];
                $swiper = [];
                foreach ($product_swiper as $value) {
                    $swiper[] = [
                        'header_group_product_id' => $product_id,
                        'swiper_type' => $value['types'],
                        'swiper_url' => $value['urlImg']
                    ];
                }
                $res3 = model('HeaderGroupProductSwiper')->saveAll($swiper);
                if (!$res3) {
                    throw new Exception('商品轮播保存失败');
                }
            }
            model('HeaderGroup')->commit();
            model('HeaderGroupProduct')->commit();
            model('HeaderGroupProductSwiper')->commit();
            exit_json();
        } catch (\Exception $e) {
            model('HeaderGroup')->rollback();
            model('HeaderGroupProduct')->rollback();
            model('HeaderGroupProductSwiper')->rollback();
            exit_json(-1, $e->getMessage());
        }
    }

    /**
     * 城主自提点
     */
    public function getAddressList()
    {
        $address_list = db("header_pick_address")->where('header_id', $this->header_id)->field('id, name, address, address_det')->select();
        exit_json(1, '请求成功', $address_list);
    }

    /**
     * 添加自提点
     */
    public function addAddress()
    {
        $data = [
            'name' => input('name'),
            'address' => input('address'),
            'address_det' => input('address_det'),
            'header_id' => $this->header_id
        ];
        $id = db('header_pick_address')->insertGetId($data);
        $res = [
            'id' => $id,
            'name' => $data['name'],
            'address' => $data['address'],
            'address_det' => $data['address_det']
        ];
        exit_json(1, '操作成功', $res);
    }

    /**
     * 编辑自提点
     */
    public function editAddress()
    {
        $id = input('id');
        $name = input('name');
        $address = input('address');
        $address_det = input('address_det');
        $res = db('header_pick_address')->where('id', $id)->update([
            'name' => $name,
            'address' => $address,
            'address_det' => $address_det,
            'update_time' => time()
        ]);
        if ($res) {
            exit_json(1, '修改成功', [
                'id' => $id,
                'name' => $name,
                'address' => $address,
                'address_det' => $address_det,
            ]);
        } else {
            exit_json(-1, '编辑失败');
        }
    }

    /**
     * 删除自提点
     */
    public function delAddress()
    {
        $address_id = input('address_id');
        $res = db('HeaderPickAddress')->where('id', $address_id)->delete();
        if ($res) {
            exit_json();
        } else {
            exit_json(-1, '删除失败');
        }

    }

    /**
     * 编辑团购获取详情
     */
    public function editGroupGetDetail()
    {
        $group_id = input('group_id');
        $group = HeaderGroup::get($group_id);
        $group['product_list'] = HeaderGroupProduct::all(function ($query) use ($group_id) {
            $query->where(['header_group_id' => $group_id])->order('ord');
        });
        foreach ($group['product_list'] as $value) {
            $header_group_product_id = $value['id'];
            $value['img_list'] = HeaderGroupProductSwiper::all(function ($query) use ($header_group_product_id) {
                $query->where("header_group_product_id", $header_group_product_id)->field("id, swiper_type types, swiper_url urlImg");
            });
        }
        exit_json(1, "请求成功", $group);
    }

    /**
     * 获取产品库商品信息
     */
    public function getStockProduct()
    {
        $page = input('page') ? input('page') : 0;
        $page_num = input('page_num') ? input('page_num') : 0;
        $product_list = model('Product')->where('header_id', $this->header_id)->limit($page * $page_num, $page_num)->select();
        foreach ($product_list as $product) {
            $swiper = model('ProductSwiper')->where('product_id', $product['id'])->field('type types, url urlImg')->select();
            $product['img_list'] = $swiper;
            $record_list = model('HeaderGroupProduct')->where('base_id', $product['id'])->order('id desc, ord')->select();
            foreach ($record_list as $item) {
                $item['img_list'] = model('HeaderGroupProductSwiper')->where('header_group_product_id', $item['id'])->field('swiper_type types, swiper_url urlImg')->select();
            }
            $product['record_list'] = $record_list;
            $product['base_id'] = $product['id'];
            $product["product_desc"] = $product['desc'];
        }
        exit_json(1, '请求成功', $product_list);
    }

    /**
     * 获取军团列表
     */
    public function getRecordList()
    {
        $page = input('page');
        $page_num = input('page_num');
        $keywords = input("keywords");
        $header_group = model('HeaderGroup')->where('header_id', $this->header_id)->whereLike("group_title", "%$keywords%")->field('id, group_title, status, open_time')->order("create_time desc")->limit($page * $page_num, $page_num)->select();
        foreach ($header_group as $item) {
            $list = model("Order")->alias("a")->join("User b", "a.user_id=b.id")->where("a.header_group_id", $item['id'])->group("a.user_id")->field("b.avatar")->select();
            //参团人员
            $item['join_list'] = $list;
            //团购产品列表
            $product_list = model('HeaderGroupProduct')->where('header_group_id', $item['id'])->select();
            foreach ($product_list as $value) {
                $value['product_img'] = model('HeaderGroupProductSwiper')->where('header_group_product_id', $value['id'])->field('swiper_type types, swiper_url urlImg')->select();
            }
            $item['product_list'] = $product_list;
        }
        $data = $header_group;
        exit_json(1, '请求成功', $data);
    }

    /**
     * 获取团购详情
     */
    public function getGroupDetail()
    {
        $group_id = input('group_id');
        $group = model('HeaderGroup')->alias('a')->join('Header b', 'a.header_id=b.id')->where('a.id', $group_id)->field('a.id, a.group_title, a.group_notice, a.status, b.nick_name, b.head_image')->find();
        $product_list = model('HeaderGroupProduct')->where('header_group_id', $group_id)->field('id, product_name, remain, sell_num, commission, market_price, group_price, product_desc')->order('ord')->select();
        foreach ($product_list as $item) {
            $item['img_list'] = model('HeaderGroupProductSwiper')->where('header_group_product_id', $item['id'])->field('swiper_type types, swiper_url urlImg')->select();
            $item["remain"] = $item["remain"] == -1 ? "无限" : $item["remain"];
        }
        $group['product_list'] = $product_list;
        exit_json(1, '请求成功', $group);
    }

    /**
     * 开启团购
     */
    public function startGroup()
    {
        $group_id = input('group_id');
        $group = HeaderGroup::get($group_id);
        if ($group['header_id'] != $this->header_id) {
            exit_json(-1, '登陆用户与团购创建用户不同');
        } else {
            if ($group['status'] != 0) {
                exit_json(-1, '当前团购不是未开启状态');
            }
            $res = $group->save(['status' => 1, 'open_time' => date('Y-m-d H:i')]);
            if ($res) {
                exit_json();
            } else {
                exit_json(-1, '开启失败');
            }
        }
    }

    /**
     * 更改头像
     */
    public function modifyAvatar()
    {
        $avatar_url = input('avatar_url');
        if (!$avatar_url) {
            exit_json(-1, '参数错误');
        }
        $res = model('Header')->save(['head_image' => $avatar_url], ['id' => $this->header_id]);
        if ($res) {
            exit_json();
        } else {
            exit_json(-1, '修改失败');
        }
    }

    /**
     *  更改昵称
     */
    public function modifyNickName()
    {
        $nick_name = input('nick_name');
        $res = model('Header')->save(['nick_name' => $nick_name], ['id' => $this->header_id]);
        if ($res) {
            exit_json();
        } else {
            exit_json(-1, '修改失败');
        }
    }

    /**
     * 获取城主基础信息
     */
    public function getHeaderInfo()
    {
        $header = model('Header')->where('id', $this->header_id)->field('id header_id, name, nick_name, head_image, amount_able+amount_lock amount, amount_able')->find();
        exit_json(1, '请求成功', $header);
    }

    /**
     * 我的团长列表
     */
    public function getMyLeader()
    {
        $page = input('page');
        $page_num = input('page_num');
        $status = input('status');
        $list = model('ApplyLeaderRecord')->alias('a')->join('User b', 'a.user_id=b.id')->where(['a.header_id' => $this->header_id, 'a.status' => $status])->field('a.id, a.name, a.status, b.avatar')->limit($page * $page_num, $page_num)->select();
        exit_json(1, '请求成功', $list);
    }

    /**
     * 团长申请详情
     */
    public function getMyLeaderDet()
    {
        $apply_id = input('id');
        $data = ApplyLeaderRecord::get($apply_id);

        exit_json(1, '请求成功', $data);
    }

    /**
     * 同意团长申请
     */
    public function agreeLeader()
    {
        $apply_id = input('id');
        $data = ApplyLeaderRecord::get($apply_id);
        if ($data && $data['status'] == 0) {
            if ($data['header_id'] != $this->header_id) {
                exit_json(-1, '权限错误');
            }
            $data->save(['status' => 1]);
            model('User')->where('id', $data['user_id'])->find()->save(['role_status' => 2, "header_id" => $this->header_id, "telephone" => $data['telephone'], "address" => $data["address"], "residential" => $data["residential"], "neighbours" => $data["neighbours"], "have_group" => $data["have_group"], "have_sale" => $data["have_sale"], "work_time" => $data["work_time"], "name" => $data["name"]]);
            exit_json();
        } else {
            exit_json(-1, '申请记录不存在或已处理');
        }
    }

    /**
     * 团长申请拒绝
     */
    public function refuseLeader()
    {
        $apply_id = input('id');
        $data = ApplyLeaderRecord::get($apply_id);
        $reason = input('reason');
        if ($data && $data['status'] == 0) {
            if ($data['header_id'] != $this->header_id) {
                exit_json(-1, '权限错误');
            }
            $data->save(['status' => 2, 'remarks' => $reason]);
            exit_json();
        } else {
            exit_json(-1, '申请记录不存在或已处理');
        }
    }

    /**
     * 结束军团
     */
    public function closeGroup()
    {
        $group_id = input("group_id");
        $group = model("HeaderGroup")->where("id", $group_id)->where("header_id", $this->header_id)->find();
        if (!$group || $group["status"] == 2) {
            exit_json(-1, "团购不存在或已结束");
        } else {
            $res = $group->save(["status" => 2]);
            if ($res) {
                model("Group")->save(["status" => 2, "close_time" => date("Y-m-d H:i")], ["header_group_id" => $group_id, "status" => ["neq", 2]]);
                //军团销售汇总
                $sum_money = model("HeaderGroupProduct")->where("header_group_id", $group_id)->sum("sell_num*group_price*(1-commission/100)");
                //团购销售汇总
                $group_list = model("GroupProduct")->where("header_group_id", $group_id)->group("group_id")->field("sum(sell_num*group_price*commission/100) sum_money, leader_id, group_id")->select();
                model("Header")->where("id", $group["header_id"])->setInc("amount_lock", $sum_money);
                model("HeaderMoneyLog")->save([
                    "header_id" => $group["header_id"],
                    "type" => 1,
                    "money" => $sum_money,
                    "order_no" => $group_id
                ]);
                foreach ($group_list as $item) {
                    model("User")->where("id", $item["leader_id"])->setInc("amount_lock", $item["sum_money"]);
                    model("LeaderMoneyLog")->isUpdate(false)->save([
                        "leader_id" => $item["leader_id"],
                        "type" => 1,
                        "money" => $item["sum_money"],
                        "order_no" => $item["group_id"]
                    ]);
                }
                exit_json();
            } else {
                exit_json(-1, "操作失败");
            }
        }

    }

    /**
     * 获取订单列表
     */
    public function getOrderList()
    {
        $status = input("status");
        $page = input("page");
        $page_num = input("page_num");
        $keywords = input("keywords");
        $model = model("HeaderGroup")->where("header_id", $this->header_id);
        if (is_numeric($status) && $status > 0) {
            $model->where("status", $status);
        } else {
            $model->where("status", "gt", 0);
        }
        if ($keywords) {
            $model->whereLike("group_title", "%$keywords%");
        }
        $list = $model->field("id, group_title, status")->limit($page * $page_num, $page_num)->order("create_time desc")->select();
        foreach ($list as $value) {
            $product_list = model("HeaderGroupProduct")->where("header_group_id", $value['id'])->where("sell_num", "gt", 0)->field("product_name, sell_num")->select();
            $value["product_list"] = $product_list;
            $sale_detail = model("Order")->where("header_group_id", $value["id"])->field("sum(order_money) sum_money, sum(refund_money) sum_refund")->find();
            if (is_null($sale_detail["sum_money"])) {
                $sale_detail["sum_money"] = 0;
            }
            if (is_null($sale_detail["sum_refund"])) {
                $sale_detail["sum_refund"] = 0;
            }
            $value["sale_detail"] = $sale_detail;
        }
        $data = $list;
        exit_json(1, "请求成功", $data);
    }

    /**
     * 获取团购订单列表
     */
    public function getGroupList()
    {
        $id = input("group_id");//军团id
        $keywords = input("keywords");
        $model = model("Group")->alias("a")->join("User b", "a.leader_id=b.id")->where("a.header_group_id", $id);
        if ($keywords) {
            $model->where("b.user_name|b.telephone", "like", "%$keywords%");
        }
        $list = $model->field("a.id, a.pick_status, b.id leader_id, b.user_name")->select();
        foreach ($list as $value) {
            $value["product_list"] = model("GroupProduct")->where("group_id", $value["id"])->field("sell_num, product_name, group_price")->select();
            $value["refund_money"] = model("Order")->where("group_id", $value["id"])->sum("refund_money");
        }
        exit_json(1, "请求成功", $list);
    }

    /**
     * 团购订单处理
     */
    public function solveGroup()
    {
        $group_id = input("group_id");
        $group = model("Group")->where("id", $group_id)->where("header_id", $this->header_id)->find();
        if ($group) {
            $res = $group->save(["pick_status" => 1]);
            if ($res) {
                exit_json();
            } else {
                exit_json(-1, "处理失败");
            }
        } else {
            exit_json(-1, "团购不存在");
        }

    }

    /**
     * 获取退货商品列表
     */
    public function getRefundList()
    {
        $status = input("status");
        if (!is_numeric($status) || !in_array($status, [0, 1, 2])) {
            exit_json(-1, "状态错误");
        }
        $page = input("page");
        $page_num = input("page_num");
        $list = model("OrderRefund")->where("header_id", $this->header_id)->where("status", $status)->field("id, product_name, create_time")->limit($page * $page_num, $page_num)->select();
        exit_json(1, "请求成功", $list);
    }

    /**
     * 获取退款详情
     */
    public function getRefundDetail()
    {
        $refund_id = input('id');
        $refund = model("OrderRefund")->where("id", $refund_id)->find();
        if (!$refund) {
            exit_json(-1, '退款记录不存在');
        } else {
            $data = [];
            //订单详情
            $order = model("Order")->where("order_no", $refund["order_no"])->find();
            $order_detail = [
                "order_no" => $order["order_no"],
                "user_name" => $order["user_name"],
                "user_telephone" => $order["user_telephone"],
                "create_time" => $order["create_time"]
            ];
            $data["order_detail"] = $order_detail;
            //团购详情
            $group = model("Group")->alias("a")->join("User b", "a.leader_id=b.id")->where("a.id", $refund["group_id"])->field("a.*, b.user_name, b.telephone")->find();
            $group_detail = [
                "title" => $group["title"],
                "user_name" => $group["user_name"],
                "telephone" => $group["telephone"],
                "remarks" => $refund["reason"],
                "refuse_reason" => $refund["refuse_reason"]
            ];
            $data["group_detail"] = $group_detail;
            $product = model("GroupProduct")->where("id", $refund["product_id"])->find();
            $data["sale_amount"] = $refund["num"];
            $data["group_price"] = $refund["group_price"];
            $data["commission_rate"] = $product["commission"];
            $data["product_name"] = $product["product_name"];
            $data["status"] = $refund["status"];
            exit_json(1, '请求成功', $data);
        }
    }

    /**
     * 同意退款
     */
    public function agreeRefund()
    {
        $refund_id = input("refund_id");
        $status = input("status");
        $refund = model("OrderRefund")->where("header_id", $this->header_id)->where("id", $refund_id)->find();
        if (!$refund && $refund["status"] != 0) {
            exit_json(-1, "退款申请已处理或不存在");
        } else {
            if ($status == 1) {
                //同意申请退款
                $weixin = new WeiXinPay();
                $order = model("Order")->where("order_no", $refund["order_no"])->find();
                $refund_money = $refund["num"] * $refund["group_price"];
                $refund_order = [
                    "order_no" => $refund["order_no"],
                    "trade_no" => $order["transaction_id"],
                    "refund_id" => $refund["refund_no"],
                    "total_money" => $order["order_money"],
                    "refund_money" => $refund_money,
                    "shop_id" => $refund["leader_id"]
                ];
                Log::error($refund_order);
                $res = $weixin->refund($refund_order);
                if ($res) {
                    //军团退货商品处理
                    $header_product = model("HeaderGroupProduct")->where("id", $refund["header_product_id"])->find();
                    //团购
                    $group = model("Group")->where("id", $refund["group_id"])->find();
                    $group_product = model("GroupProduct")->where("id", $refund["product_id"])->find();
                    //订单商品详情
                    $product = model("OrderDet")->where("product_id", $refund["product_id"])->where("order_no", $refund["order_no"])->find();

                    //军团商品处理
                    $header_product->save([
                        "refund_num" => $header_product["refund_num"] + $refund["num"],
                        "remain" => $header_product["remain"] + $refund["num"],
                        "sell_num" => $header_product["sell_num"] - $refund["num"]
                    ]);
                    //团购商品处理
                    $group_product->save([
                        "refund_num" => $group_product["refund_num"] + $refund["num"],
                        "sell_num" => $group_product["sell_num"] - $refund["num"]
                    ]);
                    //订单处理
                    $order->save(["refund_money" => $order["refund_money"] + $refund_money]);
                    $product->save(["back_num" => $refund["num"], "status" => 2]);

                    //佣金
                    $commission = $group_product["commission"] * ($refund["num"] * $refund["group_price"]) / 100;
                    //城主
                    $money = $refund["num"] * $refund["group_price"] - $commission;

                    $header_money = model("HeaderMoneyLog");
                    $leader_money = model("LeaderMoneyLog");
                    //军团是否已结束
                    if ($group["status"] == 2) {
                        $header_money->save([
                            "header_id" => $refund["header_id"],
                            "type" => 2,
                            "money" => -$money,
                            "order_no" => $refund["id"]
                        ]);

                        $leader_money->save([
                            "leader_id" => $refund["leader_id"],
                            "type" => 2,
                            "money" => -$commission,
                            "order_no" => $refund["id"]
                        ]);
                        if ($order["commission_status"] == 1) {
                            //佣金已经处理过，退款同时减佣金及城主收入
                            model("Header")->where("id", $refund["header_id"])->setDec("amount_able", $money);
                            model("User")->where("id", $refund["leader_id"])->setDec("amount_able", $commission);
                        } else {
                            model("Header")->where("id", $refund["header_id"])->setDec("amount_lock", $money);
                            model("User")->where("id", $refund["leader_id"])->setDec("amount_lock", $commission);
                        }
                    }
                    $refund->save(["status" => 1]);
                    exit_json();
                } else {
                    exit_json(-1, "退款处理失败");
                }
            }
            if ($status == 2) {
                //拒绝申请退款
                //拒绝原因
                $refuse_reason = input("refuse_reason");
                $res = $refund->save(["status" => 2, "refuse_reason" => $refuse_reason]);
                if ($res) {
                    exit_json(1, "处理成功");
                } else {
                    exit_json(-1, "处理失败");
                }
            }
        }
    }

    /**
     * 获取指定军团下团长信息
     */
    public function getGroupUser()
    {
        $group_id = input("group_id");
        $user_list = model("Group")->alias("a")->join("User b", "a.leader_id=b.id")->where("a.header_group_id", $group_id)->field("a.leader_id, b.user_name, a.title, a.close_time")->select();
        exit_json(1, "请求成功", $user_list);
    }

    /**
     * 发放佣金
     */
    public function deliveryCommission()
    {
        $group_id = input("group_id");
        $leader_ids = input("leader_ids");
        //判断军团是否已经处理过
        $header_group = model("HeaderGroup")->where("id", $group_id)->find();
        if ($header_group["commission_status"] != 0) {
            exit_json(-1, "佣金已处理过");
        }
        //军团销售汇总
//        $sum_money = model("HeaderGroupProduct")->where("header_group_id", $group_id)->sum("sell_num*group_price*(1-commission/100)");
        $sum_money = model("GroupProduct")->where("header_group_id", $group_id)->sum("sell_num*group_price*(1-commission/100)");

        //团购销售汇总
        $group_list = model("GroupProduct")->where("header_group_id", $group_id)->whereIn("leader_id", $leader_ids)->group("group_id")->field("sum(sell_num*group_price*commission/100) sum_money, leader_id")->select();

        $header = model("Header")->where("id", $this->header_id)->find();

        $header->save([
            "amount_able" => $header["amount_able"] + $sum_money,
            "amount_lock" => $header["amount_lock"] - $sum_money
        ]);

        foreach ($group_list as $item) {
            $leader = model("User")->where("id", $item["leader_id"])->find();
            $leader->save([
                "amount_able" => $leader["amount_able"] + $item["sum_money"],
                "amount_lock" => $leader["amount_lock"] - $item["sum_money"]
            ]);
            model("Order")->isUpdate(true)->save(["commission_status" => 1], ["header_group_id" => $group_id]);
        }
        $header_group->save(["commission_status" => 1, "commission_time" => date("Y-m-d H:i:s")]);
        exit_json();
    }

    /**
     * 提现
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function withDraw()
    {
        $header = model("Header")->where("id", $this->header_id)->find();
        if (!$header["open_id"]) {
            exit_json(2, "账户未绑定微信号");
        }
        $amount = input("money");
//        if(!$amount || $amount<10){
//            exit_json(-1, "提现金额不合法");
//        }
        //计算提现手续费及实际到账金额
        $rate = $header["rate"];
        $fee = round($amount * $rate, 2);
        $money = $amount - $fee;
        $order_no = getOrderNo();
        model("WithdrawLog")->save([
            "role" => 1,
            "user_id" => $this->header_id,
            "amount" => $amount,
            "fee" => $fee,
            "money" => $money,
            "status" => 0,
            "order_no" => $order_no

        ]);
        $withdraw_id = model("WithdrawLog")->getLastInsID();
        $log_model = model("WithdrawLog")->where("id", $withdraw_id)->find();
        $weixin = new WeiXinPay();

        $res = $weixin->withdraw([
            "open_id" => $header["open_id"],
            "amount" => $money,
            "check_name" => "NO_CHECK",
            "desc" => "军团提现到账",
            "order_no" => $order_no
        ]);
        if (!is_bool($res)) {
            $log_model->save(["withdraw_time" => $res["payment_time"], "status" => 1]);
            $header->setDec("amount_able", $amount);
            model("HeaderMoneyLog")->saveAll([
                [
                    "header_id" => $this->header_id,
                    "type" => "3",
                    "money" => $money,
                    "order_no" => $order_no
                ],
                [
                    "header_id" => $this->header_id,
                    "type" => "4",
                    "money" => $fee,
                    "order_no" => $order_no
                ]
            ]);
            exit_json();
        } else {
            exit_json(-1, "提现操作失败");
        }
    }

    /**
     * 绑定微信号
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function setOpenId()
    {
        $open_id = input("open_id");
        $header = model("Header")->where("id", $this->header_id)->find();
        $res = $header->save([
            "open_id" => $open_id
        ]);
        if ($res) {
            exit_json();
        } else {
            exit_json(-1, "绑定失败");
        }
    }

    /**
     * 获取余额列表
     */
    public function getMoneyLog()
    {
        $type_arr = [
            "1" => "军团结算",
            "2" => "退款冲减",
            "3" => "提现冲减",
            "4" => "提现手续费"
        ];
        $page = input("page");
        $page_num = input("page_num");
        $list = model("HeaderMoneyLog")->where("header_id", $this->header_id)->field("id, type, money, order_no, create_time")->limit($page * $page_num, $page_num)->order("create_time desc")->select();
        foreach ($list as $item) {
            $item["type_desc"] = $type_arr[$item["type"]];
        }
        exit_json(1, "请求成功", $list);
    }

    /**
     * 获取余额变动明细
     */
    public function getMoneyLogDetail()
    {
        $log_id = input("id");
        $detail = model("HeaderMoneyLog")->where("id", $log_id)->find();
        switch ($detail["type"]) {
            case 1:
                $header_group = model("HeaderGroup")->where("id", $detail["order_no"])->find();
                $product_list = model("HeaderGroupProduct")->where("header_group_id", $detail["order_no"])->field("product_name, commission, sell_num, group_price")->select();
                exit_json(1, "", $product_list);
                break;
            case 2:
                $refund = model("OrderRefund")->where("order_no", $detail["order_no"])->find();
                $data = [
                    "product_name" => $refund["product_name"],
                    "group_price" => $refund["group_price"],
                    "num" => $refund["num"]
                ];
                $product = model("HeaderGroupProduct")->where("id", $refund["header_product_id"])->find();
                $data["commission"] = $product["commission"];
                $order = model("Order")->where("order_no", $refund["order_no"])->find();
                $data["order_no"] = $order["order_no"];
                $data["user_name"] = $order["user_name"];
                $data["user_telephone"] = $order["user_telephone"];
                $data["create_time"] = $refund["create_time"];
                $data["opera_time"] = $refund["update_time"];
                $group = model("Group")->alias("a")->join("User b", "a.leader_id=b.id")->where("a.id", $refund["group_id"])->field("a.*, b.user_name, b.telephone")->find();
                $data["title"] = $group["title"];
                $data["leader_name"] = $group["user_name"];
                $data["telephone"] = $group["telephone"];
                $data["remarks"] = $refund["remarks"];
                break;
            default:
                exit_json(-1, "明细不存在");
        }
    }


}