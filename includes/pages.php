<?php
/**
 * 안내 페이지 — 이용약관 · 개인정보처리방침 · 배송/교환/환불.
 *
 * 푸터가 이 세 곳을 가리키는데 워드프레스에 페이지가 없어 전부 404 였다.
 * 플러그인이 없으면 만들어 둔다. 프로덕션에 플러그인을 켤 때도 같은 일이 일어난다.
 *
 * 만들어진 다음에는 워드프레스 관리자에서 여느 페이지처럼 고치면 된다 —
 * 여기 있는 글은 처음 한 번만 들어간다. 이미 같은 슬러그의 페이지가 있으면 손대지 않는다.
 *
 * @package Duckhoo\Redesign
 */

declare( strict_types = 1 );

namespace Duckhoo\Redesign\Pages;

defined( 'ABSPATH' ) || exit;

const VERSION_OPTION = 'duckhoo_pages_version';
const VERSION        = 4;   // 3: /price/ 가격표 (2026-09-28) · 4: /liquid-guide/ · /mtl-vs-dl/ 안내 글 (2026-10-01, 「전담 액상」 목표)

/**
 * 가게 정보. 한 곳에서 고치면 세 페이지가 같이 바뀐다.
 *
 * @return array<string,string>
 */
function shop(): array {
	return apply_filters(
		'duckhoo_shop_info',
		array(
			'name'     => '액상덕후',
			'company'  => '투더문',
			'ceo'      => '백시문',
			'address'  => '대구광역시 중구 경상감영길 21, 3층(동문동)',
			'biz_no'   => '642-08-02808',
			'sale_no'  => '제 2025-대구중구-0487 호',
			'tel'      => '010-5133-5852',
			'hours'    => function_exists( '\\Duckhoo\\Redesign\\Front\\hours_text' ) ? \Duckhoo\Redesign\Front\hours_text() : '평일 11:00–18:00 · 점심 12:00–13:00 · 주말 · 법정 공휴일 휴무',
			'ship_fee' => '2,500원',
			'ship_free'=> '30,000원',
		)
	);
}

/**
 * 페이지 정의.
 *
 * @return array<string,array{title:string,content:string}>
 */
function definitions(): array {
	$s = shop();
	return apply_filters( 'duckhoo_pages', array(
		'shipping' => array(
			'title'   => '배송 · 교환 · 환불 안내',
			'content' => shipping_content( $s ),
		),
		'terms'    => array(
			'title'   => '이용약관',
			'content' => terms_content( $s ),
		),
		'privacy'  => array(
			'title'   => '개인정보처리방침',
			'content' => privacy_content( $s ),
		),
		// 2026-09-28: 「액상 가격」 계열 검색(28일 290 노출)에 답하는 표. 값은 숏코드가 그때그때 읽는다.
		'price'    => array(
			'title'   => '전자담배 액상 가격표',
			'content' => '[duckhoo_price_table]',
		),
		// 2026-10-01: 「전담 액상」 「전자담배 액상」 같은 넓은 말은 상품 목록보다 **글**이 먼저 걸린다.
		// 숫자(종수 · 값)는 적지 않는다 — DB 글이라 바뀌면 거짓이 된다. 값은 가격표 · 분류로 보낸다.
		'liquid-guide' => array(
			'title'   => '전자담배 액상 고르는 법 — 입호흡 · 폐호흡 · 니코틴 · 용량',
			'content' => guide_content( $s ),
		),
		'mtl-vs-dl'    => array(
			'title'   => '입호흡 액상과 폐호흡 액상의 차이',
			'content' => mtl_dl_content( $s ),
		),
	) );
}

/**
 * 전자담배 액상 고르는 법. 사실만 — 기기 · 농도 · 용량 · 값 · 주문. 광고 제한 낱말(건강 · 금연 · 순하다 · 해롭지 않다)은 쓰지 않는다.
 *
 * @param array<string,string> $s 가게 정보.
 * @return string
 */
function guide_content( array $s ): string {
	$mtl   = home_url( '/product-category/입호흡-액상/' );
	$dl    = home_url( '/product-category/폐호흡-액상/' );
	$price = home_url( '/price/' );
	$diff  = home_url( '/mtl-vs-dl/' );
	$shop  = home_url( '/shop/' );
	$novo  = home_url( '/product-category/novo-liquid/' );
	return '
<p>전자담배 액상(전담 액상)은 <strong>쓰는 기기</strong>에 맞춰 고르는 것이 먼저입니다. 그다음이 니코틴 농도, 용량, 맛 계열, 값 순서입니다. ' . $s['name'] . '에서 파는 액상을 기준으로 고르는 순서를 정리했습니다.</p>

<h2>1. 기기부터 본다 — 입호흡인가, 폐호흡인가</h2>
<ul>
<li><strong>입호흡(MTL) 기기</strong> — 팟 · 소형 기기. 코일 저항이 1옴 안팎이고 출력이 낮습니다. 담배처럼 입에 머금었다 들이마시는 방식이라 <a href="' . esc_url( $mtl ) . '">입호흡 액상</a>을 씁니다.</li>
<li><strong>폐호흡(DL) 기기</strong> — 서브옴(1옴 미만) 코일의 고출력 기기 · 탱크. 연기를 바로 들이마시는 방식이라 <a href="' . esc_url( $dl ) . '">폐호흡 액상</a>을 씁니다.</li>
<li>서로 바꿔 넣으면 맛과 연무가 제대로 나지 않고 코일이 빨리 탑니다. 기기 설명서의 권장 저항 · 출력을 먼저 확인하세요. 둘의 차이는 <a href="' . esc_url( $diff ) . '">입호흡 액상과 폐호흡 액상의 차이</a>에 표로 정리해 두었습니다.</li>
</ul>

<h2>2. 니코틴 농도 — 이름의 「mg」 읽는 법</h2>
<ul>
<li>상품 이름의 <strong>(9.8mg / 30ml)</strong> 가 농도와 용량입니다. mg 은 1ml 에 든 니코틴 양입니다.</li>
<li>입호흡 액상은 <strong>9.8mg</strong> 이 가장 흔합니다. 저출력으로 피우는 만큼 농도가 높은 쪽입니다.</li>
<li>폐호흡 액상은 <strong>3mg</strong> 안팎이 흔합니다. 한 번에 들이마시는 양이 많아 농도가 낮은 쪽입니다.</li>
<li>농도가 0 인 제품은 <strong>무니코틴 액상</strong>으로 따로 분류돼 있습니다.</li>
<li>2026년 4월 24일 개정 담배사업법 시행 뒤 니코틴 액상은 <strong>법 시행 전 들여온 재고만</strong> 판매하며 새로 들어오지 않습니다. 품절되면 다시 채워지지 않으므로 사이트의 「재고 있음」 표시를 보고 고르시면 됩니다.</li>
</ul>

<h2>3. 용량 — 30ml 와 60ml</h2>
<ul>
<li>입호흡 액상은 <strong>30ml</strong> 한 병이 기본입니다. 팟 하나에 2ml 안팎이 들어가므로 한 병으로 열 번 넘게 채웁니다.</li>
<li>폐호흡 액상은 <strong>60ml</strong> 모드 제품이 많습니다. 탱크 용량이 커 소모가 빠르기 때문입니다.</li>
<li>같은 맛을 계속 쓰신다면 <strong>묶음(5병 · 10병 · 10+1)</strong>이 병당 가격이 가장 낮습니다. 묶음 상품은 구성과 맛을 고르는 옵션이 있습니다.</li>
</ul>

<h2>4. 맛 계열 — 이름이 말해 준다</h2>
<ul>
<li><strong>연초 · 멘솔</strong> — 타박멘솔 · 블랙멘솔 · 멘솔시가처럼 담배 계열 이름. <a href="' . esc_url( $novo ) . '">노보</a> · 노보 블랙이 대표입니다.</li>
<li><strong>과일</strong> — 더블라임 · 알로에 그레이프 · 블루레몬에이드 같은 이름. 디오리퀴드 · 화이트아웃 · 펠릭스에 많습니다.</li>
<li><strong>음료 · 디저트</strong> — 소다 · 커피 · 데저트 같은 이름.</li>
<li>처음 고르시면 낱병 한 병으로 맛을 본 뒤 묶음으로 가는 순서가 안전합니다. 액상은 개봉하면 교환 · 환불이 되지 않습니다.</li>
</ul>

<h2>5. 값 보는 법</h2>
<ul>
<li>모든 상품의 판매가와 <strong>병당 가격</strong>은 <a href="' . esc_url( $price ) . '">전 상품 가격표</a> 한 장에 있습니다. 묶음은 병당으로 비교하세요.</li>
<li>가입 즉시 적립금이 들어오고 ' . $s['ship_free'] . ' 이상은 무료배송입니다. 적립금은 결제 화면에서 바로 씁니다.</li>
</ul>

<h2>6. 주문에서 받기까지</h2>
<ul>
<li><strong>19세 이상</strong> 휴대폰 본인확인을 마친 회원만 구매할 수 있습니다. 비로그인 상태에서는 상품 사진이 가려져 보입니다.</li>
<li>결제는 <strong>무통장입금</strong>입니다. 입금자명을 주문자명과 똑같이 넣으면 입금이 자동으로 확인됩니다.</li>
<li>평일 오후 4시 이전에 입금이 확인된 주문은 <strong>당일 출고</strong>합니다. 주말 · 공휴일 출고는 없습니다. 자세한 것은 <a href="' . esc_url( home_url( '/shipping/' ) ) . '">배송 · 교환 · 환불 안내</a>.</li>
</ul>

<h2>7. 보관</h2>
<ul>
<li>직사광선과 고온을 피해 뚜껑을 닫아 세워 두세요. 색이 짙어지는 것은 니코틴 액상의 자연스러운 변화입니다.</li>
<li>어린이와 반려동물의 손이 닿지 않는 곳에 보관하세요.</li>
</ul>

<p><a href="' . esc_url( $shop ) . '">전체 상품 보기</a> · <a href="' . esc_url( $mtl ) . '">입호흡 액상</a> · <a href="' . esc_url( $dl ) . '">폐호흡 액상</a> · <a href="' . esc_url( $price ) . '">전 상품 가격표</a></p>
';
}

/**
 * 입호흡 액상 vs 폐호흡 액상 — 한 표.
 *
 * @param array<string,string> $s 가게 정보.
 * @return string
 */
function mtl_dl_content( array $s ): string {
	$mtl   = home_url( '/product-category/입호흡-액상/' );
	$dl    = home_url( '/product-category/폐호흡-액상/' );
	$price = home_url( '/price/' );
	$guide = home_url( '/liquid-guide/' );
	return '
<p>전자담배 액상은 <strong>입호흡(MTL, Mouth To Lung)</strong> 액상과 <strong>폐호흡(DL, Direct Lung)</strong> 액상으로 나뉩니다. 액상 자체가 다른 것이 아니라 <strong>어떤 기기로 어떻게 피우는가</strong>에 맞춰 니코틴 농도와 점도를 달리 만든 것입니다. 기기와 액상이 맞지 않으면 맛이 제대로 나지 않습니다.</p>

<h2>한 표로 보는 차이</h2>
<table>
<thead><tr><th></th><th>입호흡(MTL) 액상</th><th>폐호흡(DL) 액상</th></tr></thead>
<tbody>
<tr><td><strong>피우는 방식</strong></td><td>입에 머금었다가 들이마신다 (담배와 같다)</td><td>연기를 바로 깊이 들이마신다</td></tr>
<tr><td><strong>기기</strong></td><td>팟 · 소형 기기</td><td>고출력 기기 · 탱크 · 모드</td></tr>
<tr><td><strong>코일 저항</strong></td><td>1옴 안팎 이상</td><td>1옴 미만(서브옴)</td></tr>
<tr><td><strong>출력</strong></td><td>낮다 (10~20W 안팎)</td><td>높다 (40W 이상)</td></tr>
<tr><td><strong>니코틴 농도</strong></td><td>높은 편 — 9.8mg 이 흔하다</td><td>낮은 편 — 3mg 안팎</td></tr>
<tr><td><strong>용량</strong></td><td>30ml 기본</td><td>60ml 가 많다</td></tr>
<tr><td><strong>연무량</strong></td><td>적다</td><td>많다</td></tr>
<tr><td><strong>소모 속도</strong></td><td>느리다 — 30ml 로 팟 열 번 이상</td><td>빠르다</td></tr>
<tr><td><strong>' . $s['name'] . ' 분류</strong></td><td><a href="' . esc_url( $mtl ) . '">입호흡 액상</a></td><td><a href="' . esc_url( $dl ) . '">폐호흡 액상</a></td></tr>
</tbody>
</table>

<h2>어느 쪽을 골라야 하나</h2>
<ul>
<li><strong>팟 기기 · 소형 기기를 쓴다</strong> → 입호흡 액상. 노보 · 디오리퀴드 · 화이트아웃 · 펠릭스(30ml) 같은 9.8mg 액상이 여기입니다.</li>
<li><strong>서브옴 탱크 · 모드 기기를 쓴다</strong> → 폐호흡 액상. 펠릭스 모드(60ml) 같은 3mg 액상이 여기입니다.</li>
<li>같은 브랜드가 두 분류에 다 있는 경우가 있습니다(펠릭스 더블라임 입호흡 · 폐호흡). <strong>이름 뒤의 mg · ml 로 구분</strong>하세요.</li>
</ul>

<h2>바꿔 넣으면 어떻게 되나</h2>
<ul>
<li>입호흡 액상(9.8mg)을 고출력 기기에 넣으면 농도가 너무 높아 한 모금에도 목에 세게 걸립니다.</li>
<li>폐호흡 액상(3mg)을 팟 기기에 넣으면 농도가 낮아 맛이 흐리고, 점도가 높은 제품은 팟 코일이 빨리 탑니다.</li>
<li>기기 설명서의 권장 저항 · 출력을 먼저 확인하고, 그 범위에 맞는 분류에서 고르면 됩니다.</li>
</ul>

<h2>값</h2>
<p>두 분류의 판매가와 묶음 병당 가격은 <a href="' . esc_url( $price ) . '">전 상품 가격표</a>에서 한 번에 비교할 수 있습니다. 농도 · 용량 · 맛 계열까지 고르는 순서는 <a href="' . esc_url( $guide ) . '">전자담배 액상 고르는 법</a>에 있습니다. ' . $s['ship_free'] . ' 이상 무료배송, 19세 이상 본인확인 회원만 구매할 수 있습니다.</p>
';
}

/**
 * 배송 · 교환 · 환불.
 *
 * @param array<string,string> $s 가게 정보.
 * @return string
 */
function shipping_content( array $s ): string {
	return '
<h2>배송</h2>
<ul>
<li>배송비는 ' . $s['ship_fee'] . ' 이며, ' . $s['ship_free'] . ' 이상 구매하시면 무료입니다.</li>
<li>평일 오후 4시 이전에 <strong>입금이 확인된 주문</strong>은 당일 출고합니다.</li>
<li>출고일 기준 1~2일 이내에 받아보실 수 있습니다.</li>
<li>주말과 법정 공휴일에는 출고 · 배송이 되지 않습니다. <strong>' . ( function_exists( '\\Duckhoo\\Redesign\\Front\\ship_rule' ) ? \Duckhoo\Redesign\Front\ship_rule() : '금요일 오후 4시 이후에 입금이 확인된 주문과 토 · 일요일 주문은 다음 주 월요일 오후 4시에 출고됩니다.' ) . '</strong></li>
<li>택배사는 우체국택배입니다. 송장번호가 등록되면 주문내역에서 조회하실 수 있습니다.</li>
</ul>

<h2>입금 안내</h2>
<ul>
<li>이 쇼핑몰은 <strong>무통장입금</strong>으로만 결제받습니다. 카드결제는 받지 않습니다.</li>
<li><strong>입금자명을 주문자명과 똑같이</strong> 넣어 주세요. 이름이 같으면 입금이 자동으로 확인됩니다.</li>
<li>이름이 다르면 자동으로 확인되지 않아 처리가 늦어집니다. 이미 보내셨다면 1:1 문의로 알려 주세요.</li>
</ul>

<h2>교환 · 환불</h2>
<ul>
<li>미개봉 상품은 받으신 날부터 <strong>7일 이내</strong>에 교환 · 환불하실 수 있습니다.</li>
<li>단순 변심으로 교환하실 때는 왕복 배송비 5,000원, 환불하실 때는 편도 배송비 ' . $s['ship_fee'] . ' 이 부과됩니다.</li>
<li>상품에 하자가 있거나 다른 상품이 배송된 경우에는 배송비를 받지 않습니다.</li>
<li>신청은 1:1 문의 또는 ' . $s['tel'] . ' 로 먼저 연락 주신 뒤 보내 주세요.</li>
</ul>

<h3>교환 · 환불이 어려운 경우</h3>
<ul>
<li><strong>개봉한 액상</strong> — 위생상 되팔 수 없어 교환 · 환불이 어렵습니다.</li>
<li>고객님의 책임 있는 사유로 상품이 멸실되거나 훼손된 경우</li>
<li>사용 또는 일부 소비로 상품의 가치가 뚜렷하게 줄어든 경우</li>
<li>시간이 지나 다시 판매하기 어려울 정도로 가치가 줄어든 경우</li>
<li>받으신 날부터 7일이 지난 경우</li>
</ul>
<p>위 사유에 해당하더라도 「전자상거래 등에서의 소비자보호에 관한 법률」이 정한 청약철회권은 제한되지 않습니다.</p>

<h2>반품 주소</h2>
<p>' . $s['address'] . ' ' . $s['company'] . '<br>
문의 ' . $s['tel'] . ' · ' . $s['hours'] . '</p>

<h2>19세 미만 판매 금지</h2>
<p>전자담배 액상은 청소년보호법상 청소년유해약물로 지정되어 있습니다. 구매하시려면 휴대폰 본인확인을 마치셔야 하며, 19세 미만에게는 판매하지 않습니다. 니코틴은 중독성이 있는 물질입니다.</p>
';
}

/**
 * 이용약관.
 *
 * @param array<string,string> $s 가게 정보.
 * @return string
 */
function terms_content( array $s ): string {
	return '
<h2>제1조 (목적)</h2>
<p>이 약관은 ' . $s['company'] . '(이하 “회사”)이 운영하는 ' . $s['name'] . ' 온라인 쇼핑몰(이하 “몰”)에서 제공하는 서비스를 이용함에 있어 회사와 이용자의 권리 · 의무 및 책임사항을 정함을 목적으로 합니다.</p>

<h2>제2조 (정의)</h2>
<ul>
<li>“몰”이란 회사가 재화를 이용자에게 제공하기 위하여 설정한 가상의 영업장을 말합니다.</li>
<li>“이용자”란 몰에 접속하여 이 약관에 따라 서비스를 받는 회원 및 비회원을 말합니다.</li>
<li>“회원”이란 회사에 개인정보를 제공하여 회원등록을 한 자를 말합니다.</li>
</ul>

<h2>제3조 (약관의 명시와 개정)</h2>
<p>회사는 이 약관의 내용을 이용자가 쉽게 알 수 있도록 몰의 초기 화면에 게시합니다. 회사는 「전자상거래 등에서의 소비자보호에 관한 법률」, 「약관의 규제에 관한 법률」 등 관련 법을 위배하지 않는 범위에서 이 약관을 개정할 수 있으며, 개정할 때에는 적용일자와 개정사유를 명시하여 적용일자 7일 전부터 공지합니다. 이용자에게 불리하게 개정하는 경우에는 30일 전부터 공지합니다.</p>

<h2>제4조 (서비스의 제공)</h2>
<p>회사는 재화에 대한 정보 제공 및 구매계약의 체결, 구매계약이 체결된 재화의 배송을 제공합니다. 서비스는 연중무휴 1일 24시간 제공함을 원칙으로 하되, 시스템 점검 등 필요한 경우 일시 중단될 수 있습니다.</p>

<h2>제5조 (미성년자 판매 제한)</h2>
<p>몰이 판매하는 전자담배 액상은 「청소년보호법」상 청소년유해약물에 해당합니다. 회사는 <strong>만 19세 미만에게 재화를 판매하지 않으며</strong>, 구매 전 휴대폰 본인확인 절차를 거칩니다. 이용자가 연령을 허위로 알리고 구매한 경우 회사는 해당 주문을 취소할 수 있습니다.</p>

<h2>제6조 (구매신청 및 대금 지급)</h2>
<p>이용자는 몰에서 재화를 선택하고 주문 정보를 입력하여 구매를 신청합니다. 대금 지급 방법은 <strong>무통장입금(계좌이체)</strong>으로 한정합니다. 입금자명이 주문자명과 다른 경우 입금 확인이 지연될 수 있습니다.</p>

<h2>제7조 (계약의 성립)</h2>
<p>회사는 구매신청에 대하여 수신확인통지를 하며, 입금이 확인된 때에 계약이 성립합니다. 재화의 품절 등으로 계약을 이행할 수 없는 경우 회사는 그 사유를 알리고 대금을 환급합니다.</p>

<h2>제8조 (주문 취소)</h2>
<p>이용자는 <strong>입금 확인 전(주문 상태 “결제 확인 중”)</strong>까지 주문내역에서 직접 주문을 취소할 수 있습니다. 입금이 확인된 뒤의 취소는 1:1 문의 또는 ' . $s['tel'] . ' 로 요청해 주시면 환불 절차로 처리합니다.</p>

<h2>제9조 (청약철회 및 반품 · 환불)</h2>
<p>이용자는 재화를 배송받은 날부터 7일 이내에 청약철회를 할 수 있습니다. 다만 이용자의 책임 있는 사유로 재화가 멸실 · 훼손된 경우, 사용 또는 일부 소비로 재화의 가치가 뚜렷하게 감소한 경우에는 청약철회가 제한될 수 있습니다. 개봉한 액상은 위생상 교환 · 환불이 어렵습니다. 자세한 내용은 배송 · 교환 · 환불 안내를 따릅니다.</p>

<h2>제10조 (회원 탈퇴)</h2>
<p>회원은 언제든지 몰에서 탈퇴를 요청할 수 있으며, 회사는 즉시 처리합니다. 다만 「전자상거래 등에서의 소비자보호에 관한 법률」에 따라 <strong>거래기록은 5년간 보존</strong>되므로 주문 내역은 남습니다. 처리 중인 주문이 있거나 사용하지 않은 적립금이 남아 있는 경우에는 정리한 뒤 탈퇴하실 수 있습니다.</p>

<h2>제11조 (개인정보보호)</h2>
<p>회사는 이용자의 개인정보를 관련 법령에 따라 보호하며, 자세한 사항은 개인정보처리방침을 따릅니다.</p>

<h2>제12조 (분쟁 해결)</h2>
<p>회사는 이용자가 제기하는 의견과 불만을 신속하게 처리합니다. 회사와 이용자 사이에 발생한 분쟁에 관한 소송은 제소 당시 이용자의 주소를 관할하는 법원을 전속관할로 합니다.</p>

<h2>사업자 정보</h2>
<p>상호 ' . $s['company'] . ' · 대표 ' . $s['ceo'] . '<br>
주소 ' . $s['address'] . '<br>
사업자등록번호 ' . $s['biz_no'] . ' · 통신판매업 신고 ' . $s['sale_no'] . '<br>
전화 ' . $s['tel'] . ' · ' . $s['hours'] . '</p>
';
}

/**
 * 개인정보처리방침.
 *
 * @param array<string,string> $s 가게 정보.
 * @return string
 */
function privacy_content( array $s ): string {
	return '
<p>' . $s['company'] . '(이하 “회사”)은 ' . $s['name'] . ' 쇼핑몰을 운영하면서 이용자의 개인정보를 「개인정보 보호법」 등 관련 법령에 따라 보호합니다.</p>

<h2>1. 수집하는 개인정보</h2>
<ul>
<li><strong>회원가입</strong> — 아이디, 비밀번호, 이름, 휴대전화번호, 이메일</li>
<li><strong>본인확인</strong> — 휴대폰 본인확인 결과(성인 여부), 이름, 생년월일, 성별, 내 · 외국인 정보, 중복가입확인정보(DI) · 연계정보(CI)</li>
<li><strong>주문 · 배송</strong> — 수령인 이름, 배송지 주소, 연락처, 입금자명, 주문 내역</li>
<li><strong>자동 수집</strong> — 접속 IP, 쿠키, 방문 일시, 서비스 이용 기록, 기기 정보</li>
</ul>

<h2>2. 이용 목적</h2>
<ul>
<li>회원 식별과 가입 의사 확인, 부정 이용 방지</li>
<li><strong>만 19세 이상 여부 확인</strong> — 청소년보호법상 판매 제한 준수</li>
<li>재화 주문 접수, 입금 확인, 배송, 교환 · 환불 처리</li>
<li>공지사항 전달, 문의 응대, 분쟁 처리</li>
</ul>

<h2>3. 보유 및 이용 기간</h2>
<p>원칙적으로 개인정보 수집 · 이용 목적이 달성되면 지체 없이 파기합니다. 다만 관련 법령에 따라 아래 정보는 정해진 기간 동안 보관합니다.</p>
<ul>
<li>계약 또는 청약철회 등에 관한 기록 — <strong>5년</strong> (전자상거래법)</li>
<li>대금 결제 및 재화 공급에 관한 기록 — <strong>5년</strong> (전자상거래법)</li>
<li>소비자 불만 또는 분쟁 처리에 관한 기록 — <strong>3년</strong> (전자상거래법)</li>
<li>표시 · 광고에 관한 기록 — <strong>6개월</strong> (전자상거래법)</li>
<li>접속 기록 — <strong>3개월</strong> (통신비밀보호법)</li>
</ul>
<p>회원 탈퇴 시 회원 정보는 지체 없이 파기하되, 위 법정 보존 대상인 거래기록은 기간이 지날 때까지 보관합니다.</p>

<h2>4. 제3자 제공</h2>
<p>회사는 이용자의 개인정보를 제3자에게 제공하지 않습니다. 다만 배송을 위하여 아래와 같이 최소한의 정보를 전달합니다.</p>
<ul>
<li><strong>우체국택배</strong> — 수령인 이름, 주소, 연락처 (배송 완료 시까지)</li>
</ul>
<p>법령에 따라 수사기관이 적법한 절차로 요구하는 경우에는 그에 따릅니다.</p>

<h2>5. 처리 위탁</h2>
<ul>
<li><strong>휴대폰 본인확인</strong> — 본인확인기관 (성인 여부 확인)</li>
<li><strong>쇼핑몰 호스팅</strong> — 사이트 운영 및 데이터 보관</li>
</ul>
<p>회사는 위탁계약 시 개인정보가 안전하게 관리되도록 필요한 사항을 규정하고 감독합니다.</p>

<h2>6. 이용자의 권리</h2>
<p>이용자는 언제든지 자신의 개인정보를 조회 · 수정할 수 있고, 회원 탈퇴를 통해 수집 · 이용 동의를 철회할 수 있습니다. 조회 · 수정은 마이페이지에서, 탈퇴는 회원탈퇴 페이지에서 하실 수 있습니다. 열람 · 정정 · 삭제 · 처리정지 요구는 아래 연락처로 요청하실 수 있으며, 회사는 지체 없이 조치합니다.</p>

<h2>7. 쿠키</h2>
<p>회사는 장바구니 유지와 로그인 상태 확인을 위해 쿠키를 사용합니다. 이용자는 웹브라우저 설정에서 쿠키 저장을 거부할 수 있으나, 이 경우 장바구니와 로그인 기능이 정상적으로 동작하지 않을 수 있습니다.</p>

<h2>8. 안전성 확보 조치</h2>
<p>회사는 비밀번호 암호화, 접근 권한 관리, 접속 기록 보관 등 개인정보를 안전하게 보관하기 위한 조치를 취하고 있습니다.</p>

<h2>9. 개인정보 보호책임자</h2>
<p>책임자 ' . $s['ceo'] . ' (' . $s['company'] . ' 대표)<br>
연락처 ' . $s['tel'] . ' · ' . $s['hours'] . '<br>
문의는 1:1 문의 게시판으로도 받습니다.</p>

<h2>10. 권익침해 구제</h2>
<p>개인정보 침해로 도움이 필요하시면 아래 기관에 문의하실 수 있습니다.</p>
<ul>
<li>개인정보침해 신고센터 (privacy.kisa.or.kr / 국번없이 118)</li>
<li>개인정보 분쟁조정위원회 (kopico.go.kr / 1833-6972)</li>
<li>대검찰청 사이버수사과 (spo.go.kr / 1301)</li>
<li>경찰청 사이버수사국 (ecrm.police.go.kr / 국번없이 182)</li>
</ul>

<h2>11. 방침 변경</h2>
<p>이 개인정보처리방침은 시행일로부터 적용되며, 내용이 바뀔 때에는 변경 사항을 공지사항을 통해 알려 드립니다.</p>
';
}

/**
 * 없는 페이지를 만든다. 이미 있으면 손대지 않는다.
 *
 * @return void
 */
/**
 * 관리자를 열기 전에 손님이 새 페이지 주소로 먼저 오면 그 자리에서 만든다 — 404 일 때만 보므로 평소엔 비용이 없다.
 *
 * @return void
 */
function ensure_on_404(): void {
	if ( function_exists( 'is_404' ) && is_404() && (int) get_option( VERSION_OPTION, 0 ) < VERSION ) {
		$path = rawurldecode( (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ); // phpcs:ignore
		foreach ( array_keys( definitions() ) as $slug ) {
			if ( trim( $path, '/' ) === $slug ) {
				ensure();
				wp_safe_redirect( home_url( '/' . $slug . '/' ), 302 );
				exit;
			}
		}
	}
}
add_action( 'template_redirect', __NAMESPACE__ . '\\ensure_on_404', 3 );

function ensure(): void {
	if ( (int) get_option( VERSION_OPTION, 0 ) >= VERSION ) {
		return;
	}
	foreach ( definitions() as $slug => $def ) {
		$content  = trim( $def['content'] );
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			// 2026-09-21: 응대 시간 · 주말 출고 규칙이 바뀌었는데 이 페이지는 DB 에 있어 파일을 고쳐도
			// 안 따라왔다. **우리가 넣은 뒤 사람이 손대지 않은 페이지만** 새 글로 바꾼다 —
			// 관리자에서 고친 글을 덮어쓰면 안 된다.
			if ( untouched( $existing ) && trim( (string) $existing->post_content ) !== $content ) {
				wp_update_post( array( 'ID' => (int) $existing->ID, 'post_content' => $content ) );
				update_post_meta( (int) $existing->ID, '_dhr_pages_hash', md5( $content ) );
			}
			continue;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_name'      => $slug,
				'post_title'     => $def['title'],
				'post_content'   => $content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( is_int( $id ) && $id > 0 ) {
			update_post_meta( $id, '_dhr_pages_hash', md5( $content ) );
		}
	}
	update_option( VERSION_OPTION, VERSION, false );
}

/**
 * 우리가 넣은 글 그대로인가. 우리가 마지막으로 쓴 글의 해시(`_dhr_pages_hash`)와 지금 글이
 * 같으면 그렇다. 해시가 없는 옛 페이지는 만든 뒤 한 번도 수정되지 않았을 때만 (post_modified = post_date).
 *
 * @param object $post 페이지.
 * @return bool
 */
function untouched( $post ): bool {
	$hash = (string) get_post_meta( (int) $post->ID, '_dhr_pages_hash', true );
	if ( '' !== $hash ) {
		return md5( trim( (string) $post->post_content ) ) === $hash;
	}
	return (string) ( $post->post_modified_gmt ?? '' ) === (string) ( $post->post_date_gmt ?? '' );
}
add_action( 'admin_init', __NAMESPACE__ . '\\ensure' );
